package routes

import (
	"cacher/c2server/internal"
	"regexp"

	"github.com/gofiber/fiber/v2"
)

var validUsername = regexp.MustCompile(`^[a-zA-Z0-9_\-]{1,64}$`)

func SetupUsers(app *fiber.App) {
	g := app.Group("/users")
	g.Get("/", internal.AuthMiddleware(3), listUsers)
	g.Post("/", internal.AuthMiddleware(3), createUser)
	g.Delete("/:name", internal.AuthMiddleware(3), deleteUser)
}

func listUsers(c *fiber.Ctx) error {
	internal.LogDebug("users/list")

	rows, err := internal.DB.Query("SELECT name, level, world, created_at FROM users ORDER BY name")
	if err != nil {
		return c.Status(500).JSON(fiber.Map{"error": "database error"})
	}
	defer rows.Close()

	type userRow struct {
		Name      string `json:"name"`
		Level     int    `json:"level"`
		World     string `json:"world"`
		CreatedAt int64  `json:"created_at"`
	}
	var users []userRow
	for rows.Next() {
		var u userRow
		rows.Scan(&u.Name, &u.Level, &u.World, &u.CreatedAt)
		users = append(users, u)
	}
	if users == nil {
		users = []userRow{}
	}
	return c.JSON(fiber.Map{"users": users})
}

func createUser(c *fiber.Ctx) error {
	auth := internal.GetAuth(c)

	var body struct {
		Name  string `json:"name"`
		Level int    `json:"level"`
		World string `json:"world"`
	}
	if err := c.BodyParser(&body); err != nil {
		return c.Status(400).JSON(fiber.Map{"error": "invalid request body"})
	}
	if !validUsername.MatchString(body.Name) {
		return c.Status(400).JSON(fiber.Map{"error": "invalid name"})
	}
	if body.Level < 1 || body.Level > 3 {
		return c.Status(400).JSON(fiber.Map{"error": "level must be 1, 2, or 3"})
	}
	if body.Level >= auth.Level {
		return c.Status(403).JSON(fiber.Map{"error": "cannot create user with level >= your own"})
	}

	world := body.World
	if world != "" {
		if _, ok := internal.Cfg.Worlds[world]; !ok {
			return c.Status(400).JSON(fiber.Map{"error": "unknown world: " + world})
		}
		if auth.Level == 3 && world != auth.World {
			return c.Status(403).JSON(fiber.Map{"error": "level 3 users can only create users in their own world"})
		}
	} else {
		if auth.Level == 4 {
			world = internal.DefaultWorld()
		} else {
			world = auth.World
		}
	}

	internal.LogDebug("users/create: name=%s level=%d world=%s by=%s", body.Name, body.Level, world, auth.Name)

	var exists int
	err := internal.DB.QueryRow("SELECT 1 FROM users WHERE name = ?", body.Name).Scan(&exists)
	if err == nil {
		return c.Status(409).JSON(fiber.Map{"error": "user already exists"})
	}

	apiKey := internal.GenerateAPIKey(body.Level)
	_, err = internal.DB.Exec(
		"INSERT INTO users (name, api_key, level, world, created_by) VALUES (?, ?, ?, ?, ?)",
		body.Name, apiKey, body.Level, world, auth.Name,
	)
	if err != nil {
		internal.LogError("users/create: db insert failed: %v", err)
		return c.Status(500).JSON(fiber.Map{"error": "database error"})
	}

	return c.JSON(fiber.Map{"name": body.Name, "level": body.Level, "world": world, "api_key": apiKey})
}

func deleteUser(c *fiber.Ctx) error {
	auth := internal.GetAuth(c)
	name := c.Params("name")

	internal.LogDebug("users/delete: name=%s by=%s", name, auth.Name)

	var targetLevel int
	err := internal.DB.QueryRow("SELECT level FROM users WHERE name = ?", name).Scan(&targetLevel)
	if err != nil {
		return c.Status(404).JSON(fiber.Map{"error": "user not found"})
	}
	if targetLevel >= auth.Level {
		return c.Status(403).JSON(fiber.Map{"error": "cannot delete user with level >= your own"})
	}

	internal.DB.Exec("DELETE FROM users WHERE name = ?", name)
	return c.JSON(fiber.Map{"ok": true})
}
