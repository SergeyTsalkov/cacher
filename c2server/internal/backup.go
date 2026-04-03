package internal

import (
	"bytes"
	"context"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"time"

	"github.com/aws/aws-sdk-go-v2/aws"
	"github.com/aws/aws-sdk-go-v2/service/s3"
)

func RunBackup() error {
	if Cfg.BackupBucket == "" {
		LogDebug("backup: no backup_bucket configured, skipping")
		return nil
	}

	timestamp := time.Now().UTC().Format("2006-01-02T15-04-05")
	filename := "cacher-" + timestamp + ".db"
	tmpPath := filepath.Join(os.TempDir(), filename)
	defer os.Remove(tmpPath)

	LogDebug("backup: writing snapshot to %s", tmpPath)
	escapedPath := strings.ReplaceAll(tmpPath, "'", "''")
	if _, err := DB.Exec(fmt.Sprintf("VACUUM INTO '%s'", escapedPath)); err != nil {
		return fmt.Errorf("vacuum into: %w", err)
	}

	data, err := os.ReadFile(tmpPath)
	if err != nil {
		return fmt.Errorf("read snapshot: %w", err)
	}

	client := NewBackupS3Client()
	_, err = client.PutObject(context.Background(), &s3.PutObjectInput{
		Bucket:      aws.String(Cfg.BackupBucket),
		Key:         aws.String(filename),
		Body:        bytes.NewReader(data),
		ContentType: aws.String("application/octet-stream"),
	})
	if err != nil {
		return fmt.Errorf("upload: %w", err)
	}

	LogInfo("backup: uploaded %s (%d bytes) to %s", filename, len(data), Cfg.BackupBucket)
	return nil
}
