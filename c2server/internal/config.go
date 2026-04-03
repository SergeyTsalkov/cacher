package internal

import (
	"fmt"
	"os"
	"regexp"

	"github.com/BurntSushi/toml"
)

type TLSConfig struct {
	Cert string `toml:"cert"`
	Key  string `toml:"key"`
}

type R2Config struct {
	AccountID       string `toml:"account_id"`
	AccessKeyID     string `toml:"access_key_id"`
	SecretAccessKey string `toml:"secret_access_key"`
}

type Config struct {
	Port         int               `toml:"port"`
	DBPath       string            `toml:"db_path"`
	RootAPIKey   string            `toml:"root_api_key"`
	BackupBucket string            `toml:"backup_bucket"`
	TLS          *TLSConfig        `toml:"tls"`
	R2           R2Config          `toml:"r2"`
	Worlds       map[string]string `toml:"worlds"`
	Debug        bool
}

var Cfg *Config

var validRootKey = regexp.MustCompile(`^4-[A-Za-z0-9]{12}$`)

func LoadConfig(path string) {
	var cfg Config
	if _, err := toml.DecodeFile(path, &cfg); err != nil {
		fmt.Fprintf(os.Stderr, "error: cannot read config file %s: %v\n", path, err)
		os.Exit(1)
	}

	if !validRootKey.MatchString(cfg.RootAPIKey) {
		fmt.Fprintf(os.Stderr, "error: root_api_key must be in format 4-<12 alphanumeric chars>\n")
		os.Exit(1)
	}
	if cfg.R2.AccountID == "" || cfg.R2.AccessKeyID == "" || cfg.R2.SecretAccessKey == "" {
		fmt.Fprintf(os.Stderr, "error: config is missing required [r2] credentials\n")
		os.Exit(1)
	}
	if len(cfg.Worlds) == 0 {
		fmt.Fprintf(os.Stderr, "error: config [worlds] must define at least one world\n")
		os.Exit(1)
	}
	if cfg.Port == 0 {
		cfg.Port = 3000
	}
	if cfg.DBPath == "" {
		cfg.DBPath = "./cacher.db"
	}

	Cfg = &cfg
}
