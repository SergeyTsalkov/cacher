package main

import (
	"cacher/goserver/internal"
	"cacher/goserver/routes"
	"flag"
	"fmt"
	"os"
	"strings"

	"github.com/gofiber/fiber/v2"
)

func main() {
	debug := flag.Bool("debug", false, "enable debug logging")
	newdb := flag.Bool("newdb", false, "initialize database and exit")
	flag.Usage = func() {
		fmt.Fprintf(os.Stderr, "usage: c2server <config.toml> [--debug] [--newdb]\n")
	}

	// Go's flag package stops at the first non-flag argument, so separate
	// the positional config path from flags before parsing.
	var configPath string
	var flagArgs []string
	for _, arg := range os.Args[1:] {
		if strings.HasPrefix(arg, "-") {
			flagArgs = append(flagArgs, arg)
		} else if configPath == "" {
			configPath = arg
		}
	}
	flag.CommandLine.Parse(flagArgs)

	if configPath == "" {
		flag.Usage()
		os.Exit(1)
	}

	internal.LoadConfig(configPath)
	internal.Cfg.Debug = *debug

	if *newdb {
		internal.CreateDB(internal.Cfg.DBPath)
		os.Exit(0)
	}

	internal.InitDB(internal.Cfg.DBPath)

	app := fiber.New(fiber.Config{
		DisableStartupMessage: true,
	})

	app.Use(func(c *fiber.Ctx) error {
		internal.LogDebug("→ %s %s", c.Method(), c.Path())
		err := c.Next()
		internal.LogDebug("← %d", c.Response().StatusCode())
		return err
	})

	routes.SetupItems(app)
	routes.SetupPush(app)
	routes.SetupPull(app)
	routes.SetupUsers(app)

	app.Use(func(c *fiber.Ctx) error {
		return c.Status(404).JSON(fiber.Map{"error": "not found"})
	})

	internal.LogInfo("worlds: %v", worldNames())
	if internal.Cfg.BackupBucket != "" {
		internal.LogInfo("backups: enabled → %s", internal.Cfg.BackupBucket)
	}

	internal.StartScheduler()

	addr := fmt.Sprintf(":%d", internal.Cfg.Port)
	var err error
	if internal.Cfg.TLS != nil {
		internal.LogInfo("server started (https) on %s", addr)
		err = app.ListenTLS(addr, internal.Cfg.TLS.Cert, internal.Cfg.TLS.Key)
	} else {
		internal.LogInfo("server started (http) on %s", addr)
		err = app.Listen(addr)
	}
	if err != nil {
		fmt.Fprintf(os.Stderr, "error: %v\n", err)
		os.Exit(1)
	}
}

func worldNames() []string {
	names := make([]string, 0, len(internal.Cfg.Worlds))
	for name := range internal.Cfg.Worlds {
		names = append(names, name)
	}
	return names
}
