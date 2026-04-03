package internal

import (
	"context"
	"fmt"
	"regexp"
	"strings"
	"time"

	"github.com/aws/aws-sdk-go-v2/aws"
	"github.com/aws/aws-sdk-go-v2/credentials"
	"github.com/aws/aws-sdk-go-v2/service/s3"
)

var objectKeyRegex = regexp.MustCompile(`^(.+)/([^/]+)/_extract\.tar\.lz4$`)

func ItemKeyToObjectKey(key, version string) string {
	return strings.ReplaceAll(key, ":", "/") + "/" + version + "/_extract.tar.lz4"
}

func ObjectKeyToItemKey(objectKey string) (key, version string, ok bool) {
	m := objectKeyRegex.FindStringSubmatch(objectKey)
	if m == nil {
		return "", "", false
	}
	return strings.ReplaceAll(m[1], "/", ":"), m[2], true
}

func newS3Client(world string) (*s3.Client, string, error) {
	bucket, ok := Cfg.Worlds[world]
	if !ok {
		return nil, "", fmt.Errorf("unknown world: %s", world)
	}
	client := s3.New(s3.Options{
		Region:       "auto",
		BaseEndpoint: aws.String(fmt.Sprintf("https://%s.r2.cloudflarestorage.com", Cfg.R2.AccountID)),
		Credentials:  credentials.NewStaticCredentialsProvider(Cfg.R2.AccessKeyID, Cfg.R2.SecretAccessKey, ""),
	})
	return client, bucket, nil
}

func NewBackupS3Client() *s3.Client {
	return s3.New(s3.Options{
		Region:       "auto",
		BaseEndpoint: aws.String(fmt.Sprintf("https://%s.r2.cloudflarestorage.com", Cfg.R2.AccountID)),
		Credentials:  credentials.NewStaticCredentialsProvider(Cfg.R2.AccessKeyID, Cfg.R2.SecretAccessKey, ""),
	})
}

func PresignedPutURL(world, objectKey string, expiry time.Duration) (string, error) {
	client, bucket, err := newS3Client(world)
	if err != nil {
		return "", err
	}
	presigner := s3.NewPresignClient(client)
	result, err := presigner.PresignPutObject(context.Background(), &s3.PutObjectInput{
		Bucket: aws.String(bucket),
		Key:    aws.String(objectKey),
	}, func(o *s3.PresignOptions) { o.Expires = expiry })
	if err != nil {
		return "", err
	}
	return result.URL, nil
}

func PresignedGetURL(world, objectKey string, expiry time.Duration) (string, error) {
	client, bucket, err := newS3Client(world)
	if err != nil {
		return "", err
	}
	presigner := s3.NewPresignClient(client)
	result, err := presigner.PresignGetObject(context.Background(), &s3.GetObjectInput{
		Bucket: aws.String(bucket),
		Key:    aws.String(objectKey),
	}, func(o *s3.PresignOptions) { o.Expires = expiry })
	if err != nil {
		return "", err
	}
	return result.URL, nil
}

func R2HeadObject(world, objectKey string) (bool, error) {
	client, bucket, err := newS3Client(world)
	if err != nil {
		return false, err
	}
	_, err = client.HeadObject(context.Background(), &s3.HeadObjectInput{
		Bucket: aws.String(bucket),
		Key:    aws.String(objectKey),
	})
	if err != nil {
		return false, nil
	}
	return true, nil
}

func R2DeleteObject(world, objectKey string) error {
	client, bucket, err := newS3Client(world)
	if err != nil {
		return err
	}
	_, err = client.DeleteObject(context.Background(), &s3.DeleteObjectInput{
		Bucket: aws.String(bucket),
		Key:    aws.String(objectKey),
	})
	return err
}

type ListResult struct {
	Keys      []string
	NextToken *string
}

func R2ListObjects(world string, continuationToken *string) (ListResult, error) {
	client, bucket, err := newS3Client(world)
	if err != nil {
		return ListResult{}, err
	}
	input := &s3.ListObjectsV2Input{
		Bucket:  aws.String(bucket),
		MaxKeys: aws.Int32(200),
	}
	if continuationToken != nil {
		input.ContinuationToken = continuationToken
	}
	resp, err := client.ListObjectsV2(context.Background(), input)
	if err != nil {
		return ListResult{}, err
	}
	keys := make([]string, 0, len(resp.Contents))
	for _, obj := range resp.Contents {
		if obj.Key != nil {
			keys = append(keys, *obj.Key)
		}
	}
	return ListResult{Keys: keys, NextToken: resp.NextContinuationToken}, nil
}
