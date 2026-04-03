package routes

import (
	"cacher/c2server/internal"
	"regexp"
	"time"

	"github.com/gofiber/fiber/v2"
)

var validItemKey = regexp.MustCompile(`^[\w\-:]+$`)

func SetupPush(app *fiber.App) {
	g := app.Group("/push")
	g.Post("/init", internal.AuthMiddleware(2), pushInit)
	g.Post("/confirm", internal.AuthMiddleware(2), pushConfirm)
}

func pushInit(c *fiber.Ctx) error {
	auth := internal.GetAuth(c)

	var body struct {
		Key     string `json:"key"`
		Version string `json:"version"`
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

	internal.LogDebug("push/init: world=%s key=%s version=%s", auth.World, body.Key, body.Version)

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

	return c.JSON(fiber.Map{"upload_url": uploadURL, "object_key": objectKey})
}

func pushConfirm(c *fiber.Ctx) error {
	auth := internal.GetAuth(c)

	var body struct {
		Key     string `json:"key"`
		Version string `json:"version"`
	}
	if err := c.BodyParser(&body); err != nil {
		return c.Status(400).JSON(fiber.Map{"error": "invalid request body"})
	}

	internal.LogDebug("push/confirm: world=%s key=%s version=%s", auth.World, body.Key, body.Version)

	objectKey := internal.ItemKeyToObjectKey(body.Key, body.Version)
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
