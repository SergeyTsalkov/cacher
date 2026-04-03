package routes

import (
	"cacher/c2server/internal"
	"fmt"
	"sort"
	"strings"

	"github.com/gofiber/fiber/v2"
)

func SetupItems(app *fiber.App) {
	g := app.Group("/items")
	g.Post("/query", internal.AuthMiddleware(1), queryItems)
	g.Get("/:key", internal.AuthMiddleware(1), getItem)
	g.Delete("/:key/:version", internal.AuthMiddleware(2), deleteItem)
}

func queryItems(c *fiber.Ctx) error {
	auth := internal.GetAuth(c)

	var body struct {
		Keys []string `json:"keys"`
	}
	if err := c.BodyParser(&body); err != nil || body.Keys == nil {
		return c.JSON(fiber.Map{"items": []any{}})
	}
	if len(body.Keys) > 1000 {
		return c.Status(400).JSON(fiber.Map{"error": "max 1000 keys per request"})
	}

	var exactKeys, likePatterns []string
	for _, k := range body.Keys {
		if k == "" {
			continue
		}
		if strings.Contains(k, "*") {
			likePatterns = append(likePatterns, strings.ReplaceAll(k, "*", "%"))
		} else {
			exactKeys = append(exactKeys, k)
		}
	}

	query := "SELECT key, version, created_at FROM items WHERE world = ?"
	args := []any{auth.World}

	if len(exactKeys) > 0 || len(likePatterns) > 0 {
		var conds []string
		if len(exactKeys) > 0 {
			placeholders := strings.Repeat("?,", len(exactKeys))
			conds = append(conds, fmt.Sprintf("key IN (%s)", placeholders[:len(placeholders)-1]))
			for _, k := range exactKeys {
				args = append(args, k)
			}
		}
		for _, p := range likePatterns {
			conds = append(conds, "key LIKE ?")
			args = append(args, p)
		}
		query += " AND (" + strings.Join(conds, " OR ") + ")"
	}

	internal.LogDebug("items/query: world=%s keys=%v", auth.World, body.Keys)

	rows, err := internal.DB.Query(query, args...)
	if err != nil {
		return c.Status(500).JSON(fiber.Map{"error": "database error"})
	}
	defer rows.Close()

	type itemRow struct {
		Key       string `json:"key"`
		Version   string `json:"version"`
		CreatedAt int64  `json:"created_at"`
	}
	byKey := map[string]itemRow{}
	for rows.Next() {
		var r itemRow
		if err := rows.Scan(&r.Key, &r.Version, &r.CreatedAt); err != nil {
			continue
		}
		if cur, ok := byKey[r.Key]; !ok || internal.VersionCompare(r.Version, cur.Version) > 0 {
			byKey[r.Key] = r
		}
	}

	items := make([]itemRow, 0, len(byKey))
	for _, r := range byKey {
		items = append(items, r)
	}
	sort.Slice(items, func(i, j int) bool { return items[i].Key < items[j].Key })

	return c.JSON(fiber.Map{"items": items})
}

func getItem(c *fiber.Ctx) error {
	auth := internal.GetAuth(c)
	key := c.Params("key")

	internal.LogDebug("items/get: world=%s key=%s", auth.World, key)

	rows, err := internal.DB.Query(
		"SELECT version, created_at FROM items WHERE world = ? AND key = ?",
		auth.World, key,
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
	if len(versions) == 0 {
		return c.Status(404).JSON(fiber.Map{"error": "not found"})
	}

	sorted := internal.SortVersionsDesc(versions)
	return c.JSON(fiber.Map{"key": key, "versions": sorted})
}

func deleteItem(c *fiber.Ctx) error {
	auth := internal.GetAuth(c)
	key := c.Params("key")
	version := c.Params("version")

	internal.LogDebug("items/delete: world=%s key=%s version=%s", auth.World, key, version)

	var exists int
	err := internal.DB.QueryRow(
		"SELECT 1 FROM items WHERE world = ? AND key = ? AND version = ?",
		auth.World, key, version,
	).Scan(&exists)
	if err != nil {
		return c.Status(404).JSON(fiber.Map{"error": "not found"})
	}

	if err := internal.R2DeleteObject(auth.World, internal.ItemKeyToObjectKey(key, version)); err != nil {
		internal.LogError("items/delete: r2 delete failed: %v", err)
	}
	internal.DB.Exec("DELETE FROM items WHERE world = ? AND key = ? AND version = ?", auth.World, key, version)

	return c.JSON(fiber.Map{"ok": true})
}
