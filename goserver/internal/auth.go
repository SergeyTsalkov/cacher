package internal

import (
	"crypto/rand"
	"regexp"
	"sort"
	"strconv"
	"strings"

	"github.com/gofiber/fiber/v2"
)

type AuthCtx struct {
	Level int
	Name  string
	World string
	Key   string
}

var keyRegex = regexp.MustCompile(`^([1-4])-([A-Za-z0-9]{12})$`)

const base62Chars = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789"

func GenerateAPIKey(level int) string {
	buf := make([]byte, 12)
	rand.Read(buf)
	token := make([]byte, 12)
	for i, b := range buf {
		token[i] = base62Chars[int(b)%len(base62Chars)]
	}
	return strconv.Itoa(level) + "-" + string(token)
}

func DefaultWorld() string {
	keys := make([]string, 0, len(Cfg.Worlds))
	for k := range Cfg.Worlds {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	return keys[0]
}

func AuthMiddleware(minLevel int) fiber.Handler {
	return func(c *fiber.Ctx) error {
		header := c.Get("Authorization")
		key := strings.TrimPrefix(header, "Bearer ")
		ip := c.Get("X-Forwarded-For")
		if ip == "" {
			ip = c.IP()
		}

		m := keyRegex.FindStringSubmatch(key)
		if m == nil {
			LogWarn("auth: invalid key format from %s", ip)
			return c.Status(401).JSON(fiber.Map{"error": "invalid key format"})
		}
		level, _ := strconv.Atoi(m[1])

		var auth AuthCtx

		if level == 4 {
			if key != Cfg.RootAPIKey {
				LogWarn("auth: invalid root key from %s", ip)
				return c.Status(401).JSON(fiber.Map{"error": "unauthorized"})
			}
			auth = AuthCtx{Level: 4, Name: "root", World: DefaultWorld(), Key: key}
		} else {
			row := DB.QueryRow("SELECT name, level, world FROM users WHERE api_key = ?", key)
			var name, world string
			var userLevel int
			if err := row.Scan(&name, &userLevel, &world); err != nil {
				LogWarn("auth: unknown api key from %s", ip)
				return c.Status(401).JSON(fiber.Map{"error": "unauthorized"})
			}
			if _, ok := Cfg.Worlds[world]; !ok {
				return c.Status(403).JSON(fiber.Map{"error": "world '" + world + "' is no longer configured"})
			}
			auth = AuthCtx{Level: userLevel, Name: name, World: world, Key: key}
		}

		if auth.Level < minLevel {
			LogWarn("auth: %s (level %d) needs level %d for %s %s", auth.Name, auth.Level, minLevel, c.Method(), c.Path())
			return c.Status(403).JSON(fiber.Map{"error": "insufficient access level"})
		}

		c.Locals("auth", &auth)
		return c.Next()
	}
}

func GetAuth(c *fiber.Ctx) *AuthCtx {
	return c.Locals("auth").(*AuthCtx)
}
