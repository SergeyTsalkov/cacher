package internal

import (
	"database/sql"
	_ "embed"
	"fmt"
	"os"

	_ "modernc.org/sqlite"
)

//go:embed schema.sql
var schemaSQL string

var DB *sql.DB

// CreateDB is used by --newdb. Creates and initializes the DB file, then returns.
// Main will exit 0 after calling this.
func CreateDB(path string) {
	if _, err := os.Stat(path); err == nil {
		fmt.Fprintf(os.Stderr, "error: database file already exists: %s\n", path)
		fmt.Fprintf(os.Stderr, "       delete it first if you want to reinitialize.\n")
		os.Exit(1)
	}

	db, err := sql.Open("sqlite", path)
	if err != nil {
		fmt.Fprintf(os.Stderr, "error: could not create database: %v\n", err)
		os.Exit(1)
	}
	defer db.Close()

	if _, err := db.Exec(schemaSQL); err != nil {
		fmt.Fprintf(os.Stderr, "error: could not apply schema: %v\n", err)
		os.Exit(1)
	}

	fmt.Printf("database initialized: %s\n", path)
}

// InitDB is used on normal startup. Fails fast if the DB file is missing or uninitialized.
func InitDB(path string) {
	if _, err := os.Stat(path); os.IsNotExist(err) {
		fmt.Fprintf(os.Stderr, "error: database file not found: %s\n", path)
		fmt.Fprintf(os.Stderr, "       run with --newdb to initialize a new database.\n")
		os.Exit(1)
	}

	db, err := sql.Open("sqlite", path)
	if err != nil {
		fmt.Fprintf(os.Stderr, "error: could not open database: %v\n", err)
		os.Exit(1)
	}

	if _, err := db.Exec("PRAGMA journal_mode = WAL"); err != nil {
		fmt.Fprintf(os.Stderr, "error: could not set WAL mode: %v\n", err)
		os.Exit(1)
	}

	// Check that the schema exists — do not create it; that's --newdb's job.
	var tableName string
	err = db.QueryRow("SELECT name FROM sqlite_master WHERE type='table' AND name='items'").Scan(&tableName)
	if err == sql.ErrNoRows {
		fmt.Fprintf(os.Stderr, "error: database tables not found in %s\n", path)
		fmt.Fprintf(os.Stderr, "       run with --newdb to initialize the database schema.\n")
		os.Exit(1)
	}
	if err != nil {
		fmt.Fprintf(os.Stderr, "error: could not query database schema: %v\n", err)
		os.Exit(1)
	}

	DB = db
}
