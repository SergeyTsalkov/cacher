package routes

import (
	"cacher/goserver/internal"
	"time"

	"github.com/gofiber/fiber/v2"
)

func SetupPull(app *fiber.App) {
	app.Post("/pull", internal.AuthMiddleware(1), pullItem)
}

func pullItem(c *fiber.Ctx) error {
	auth := internal.GetAuth(c)

	var body struct {
		Key string `json:"key"`
	}
	if err := c.BodyParser(&body); err != nil || body.Key == "" {
		return c.Status(400).JSON(fiber.Map{"error": "key required"})
	}

	internal.LogDebug("pull: world=%s key=%s", auth.World, body.Key)

	rows, err := internal.DB.Query(
		"SELECT version, created_at FROM items WHERE world = ? AND key = ?",
		auth.World, body.Key,
	)
	if err != nil {
		return c.Status(500).JSON(fiber.Map{"error": "database error"})
	}
	defer rows.Close()

	var versions []internal.VersionRow
	for rows.Next() {
		var vr internal.VersionRow
		rows.Scan(&vr.Version, &vr.CreatedAt)
		versions = append(versions, vr)
	}
	rows.Close()

	row := internal.LatestVersion(versions)
	if row == nil {
		return c.Status(404).JSON(fiber.Map{"error": "not found"})
	}

	objectKey := internal.ItemKeyToObjectKey(body.Key, row.Version)
	exists, err := internal.R2HeadObject(auth.World, objectKey)
	if err != nil {
		return c.Status(500).JSON(fiber.Map{"error": "could not verify object"})
	}
	if !exists {
		internal.LogWarn("pull: R2 object missing for %s@%s, removing from DB", body.Key, row.Version)
		internal.DB.Exec("DELETE FROM items WHERE world = ? AND key = ? AND version = ?", auth.World, body.Key, row.Version)
		return c.Status(404).JSON(fiber.Map{"error": "not found"})
	}

	downloadURL, err := internal.PresignedGetURL(auth.World, objectKey, time.Hour)
	if err != nil {
		internal.LogError("pull: presign failed: %v", err)
		return c.Status(500).JSON(fiber.Map{"error": "could not generate download URL"})
	}

	return c.JSON(fiber.Map{
		"key":          body.Key,
		"version":      row.Version,
		"created_at":   row.CreatedAt,
		"download_url": downloadURL,
		"object_key":   objectKey,
	})
}
