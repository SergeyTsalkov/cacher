package routes

import (
	"cacher/c2server/internal"
	"regexp"
	"sort"
	"time"

	"github.com/gofiber/fiber/v2"
)

var validItemKey = regexp.MustCompile(`^[\w\.\-:]+$`)

const (
	// Clients that send "size" at or above this get a multipart upload.
	// Clients that don't send "size" (older ones) always get a single PUT URL.
	multipartThreshold = 100 << 20 // 100 MiB
	minPartSize        = 64 << 20  // 64 MiB
	maxPartSize        = 5 << 30   // 5 GiB (S3/R2 per-part limit)
	maxParts           = 10000
	partURLExpiry      = 24 * time.Hour
)

func SetupPush(app *fiber.App) {
	g := app.Group("/push")
	g.Post("/init", internal.AuthMiddleware(2), pushInit)
	g.Post("/confirm", internal.AuthMiddleware(2), pushConfirm)
	g.Post("/abort", internal.AuthMiddleware(2), pushAbort)
}

// partSizeFor picks a size that keeps the upload within maxParts. R2 requires
// all parts except the last to be the same size.
func partSizeFor(size int64) int64 {
	partSize := int64(minPartSize)
	if needed := (size + maxParts - 1) / maxParts; needed > partSize {
		const mib = 1 << 20
		partSize = (needed + mib - 1) / mib * mib
	}
	return partSize
}

func pushInit(c *fiber.Ctx) error {
	auth := internal.GetAuth(c)

	var body struct {
		Key     string `json:"key"`
		Version string `json:"version"`
		Size    int64  `json:"size"` // optional; enables multipart for large uploads
	}
	if err := c.BodyParser(&body); err != nil {
		return c.Status(400).JSON(fiber.Map{"error": "invalid request body"})
	}
	if !validItemKey.MatchString(body.Key) {
		return c.Status(400).JSON(fiber.Map{"error": "invalid key"})
	}
	if body.Version == "" {
		return c.Status(400).JSON(fiber.Map{"error": "version required"})
	}

	internal.LogDebug("push/init: world=%s key=%s version=%s size=%d", auth.World, body.Key, body.Version, body.Size)

	var exists int
	err := internal.DB.QueryRow(
		"SELECT 1 FROM items WHERE world = ? AND key = ? AND version = ?",
		auth.World, body.Key, body.Version,
	).Scan(&exists)
	if err == nil {
		return c.Status(409).JSON(fiber.Map{"error": "version already exists"})
	}

	objectKey := internal.ItemKeyToObjectKey(body.Key, body.Version)
	uploadURL, err := internal.PresignedPutURL(auth.World, objectKey, 15*time.Minute)
	if err != nil {
		internal.LogError("push/init: presign failed: %v", err)
		return c.Status(500).JSON(fiber.Map{"error": "could not generate upload URL"})
	}

	resp := fiber.Map{"upload_url": uploadURL, "object_key": objectKey}
	if body.Size < multipartThreshold {
		return c.JSON(resp)
	}

	partSize := partSizeFor(body.Size)
	if partSize > maxPartSize {
		return c.Status(400).JSON(fiber.Map{"error": "upload too large"})
	}
	numParts := int((body.Size + partSize - 1) / partSize)

	uploadID, err := internal.R2CreateMultipartUpload(auth.World, objectKey)
	if err != nil {
		internal.LogError("push/init: create multipart failed: %v", err)
		return c.Status(500).JSON(fiber.Map{"error": "could not start multipart upload"})
	}
	partURLs, err := internal.PresignedUploadPartURLs(auth.World, objectKey, uploadID, numParts, partURLExpiry)
	if err != nil {
		internal.LogError("push/init: presign parts failed: %v", err)
		internal.R2AbortMultipartUpload(auth.World, objectKey, uploadID)
		return c.Status(500).JSON(fiber.Map{"error": "could not generate part upload URLs"})
	}

	resp["multipart"] = fiber.Map{
		"upload_id": uploadID,
		"part_size": partSize,
		"part_urls": partURLs,
	}
	return c.JSON(resp)
}

func pushConfirm(c *fiber.Ctx) error {
	auth := internal.GetAuth(c)

	var body struct {
		Key      string                   `json:"key"`
		Version  string                   `json:"version"`
		UploadID string                   `json:"upload_id"` // optional; set for multipart uploads
		Parts    []internal.CompletedPart `json:"parts"`
	}
	if err := c.BodyParser(&body); err != nil {
		return c.Status(400).JSON(fiber.Map{"error": "invalid request body"})
	}

	internal.LogDebug("push/confirm: world=%s key=%s version=%s upload_id=%s", auth.World, body.Key, body.Version, body.UploadID)

	objectKey := internal.ItemKeyToObjectKey(body.Key, body.Version)

	if body.UploadID != "" {
		if len(body.Parts) == 0 {
			return c.Status(400).JSON(fiber.Map{"error": "parts required for multipart upload"})
		}
		sort.Slice(body.Parts, func(i, j int) bool { return body.Parts[i].PartNumber < body.Parts[j].PartNumber })
		if err := internal.R2CompleteMultipartUpload(auth.World, objectKey, body.UploadID, body.Parts); err != nil {
			internal.LogError("push/confirm: complete multipart failed: %v", err)
			return c.Status(400).JSON(fiber.Map{"error": "could not complete multipart upload"})
		}
	}

	exists, err := internal.R2HeadObject(auth.World, objectKey)
	if err != nil {
		return c.Status(500).JSON(fiber.Map{"error": "could not verify upload"})
	}
	if !exists {
		return c.Status(400).JSON(fiber.Map{"error": "object not found in R2 — upload may not have completed"})
	}

	internal.DB.Exec(
		"INSERT OR IGNORE INTO items (world, key, version, created_at) VALUES (?, ?, ?, unixepoch())",
		auth.World, body.Key, body.Version,
	)

	return c.JSON(fiber.Map{"ok": true})
}

// pushAbort cancels a multipart upload so R2 discards its parts.
func pushAbort(c *fiber.Ctx) error {
	auth := internal.GetAuth(c)

	var body struct {
		Key      string `json:"key"`
		Version  string `json:"version"`
		UploadID string `json:"upload_id"`
	}
	if err := c.BodyParser(&body); err != nil {
		return c.Status(400).JSON(fiber.Map{"error": "invalid request body"})
	}
	if body.UploadID == "" {
		return c.Status(400).JSON(fiber.Map{"error": "upload_id required"})
	}

	internal.LogDebug("push/abort: world=%s key=%s version=%s upload_id=%s", auth.World, body.Key, body.Version, body.UploadID)

	objectKey := internal.ItemKeyToObjectKey(body.Key, body.Version)
	if err := internal.R2AbortMultipartUpload(auth.World, objectKey, body.UploadID); err != nil {
		internal.LogError("push/abort: abort failed: %v", err)
		return c.Status(500).JSON(fiber.Map{"error": "could not abort upload"})
	}
	return c.JSON(fiber.Map{"ok": true})
}
