package internal

import (
	"fmt"
	"strings"
	"time"
)

func RunCleanup(world string) error {
	purgeAfter := int64(60 * 60 * 24) // 24 hours
	now := time.Now().Unix()

	LogInfo("[cleanup] starting for world=%s", world)

	// Phase 1: delete old settled versions from DB and R2.
	rows, err := DB.Query("SELECT key, version, created_at FROM items WHERE world = ?", world)
	if err != nil {
		return fmt.Errorf("phase1 query: %w", err)
	}
	defer rows.Close()

	byKey := map[string][]VersionRow{}
	for rows.Next() {
		var key string
		var vr VersionRow
		if err := rows.Scan(&key, &vr.Version, &vr.CreatedAt); err != nil {
			return fmt.Errorf("phase1 scan: %w", err)
		}
		byKey[key] = append(byKey[key], vr)
	}
	rows.Close()

	type deleteTarget struct {
		key     string
		version string
	}
	var toDelete []deleteTarget

	for key, versions := range byKey {
		sorted := SortVersionsDesc(versions)
		settledVersion := ""
		for _, v := range sorted {
			if now-v.CreatedAt >= purgeAfter {
				settledVersion = v.Version
				break
			}
		}
		if settledVersion == "" {
			continue
		}
		for _, v := range sorted {
			if VersionCompare(v.Version, settledVersion) < 0 {
				toDelete = append(toDelete, deleteTarget{key, v.Version})
			}
		}
	}

	for _, item := range toDelete {
		LogDebug("[cleanup] phase1: deleting old version %s@%s", item.key, item.version)
		objKey := ItemKeyToObjectKey(item.key, item.version)
		if err := R2DeleteObject(world, objKey); err != nil {
			LogError("[cleanup] phase1: r2 delete failed for %s: %v", objKey, err)
		}
		if _, err := DB.Exec("DELETE FROM items WHERE world = ? AND key = ? AND version = ?", world, item.key, item.version); err != nil {
			LogError("[cleanup] phase1: db delete failed for %s@%s: %v", item.key, item.version, err)
		}
	}

	LogInfo("[cleanup] phase1 done: deleted %d old version(s)", len(toDelete))

	// Phase 2: scan R2 for objects with no matching DB record.
	var token *string
	page := 0
	totalOrphans := 0

	for {
		result, err := R2ListObjects(world, token)
		if err != nil {
			return fmt.Errorf("phase2 list: %w", err)
		}

		type validPair struct {
			objectKey string
			key       string
			version   string
		}
		var pairs []validPair
		for _, objKey := range result.Keys {
			k, v, ok := ObjectKeyToItemKey(objKey)
			if ok {
				pairs = append(pairs, validPair{objKey, k, v})
			}
		}

		var orphanKeys []string
		if len(pairs) > 0 {
			placeholders := make([]string, len(pairs))
			args := make([]any, 0, 1+len(pairs)*2)
			args = append(args, world)
			for i, p := range pairs {
				placeholders[i] = "(?, ?)"
				args = append(args, p.key, p.version)
			}
			query := fmt.Sprintf(
				"SELECT key, version FROM items WHERE world = ? AND (key, version) IN (VALUES %s)",
				strings.Join(placeholders, ", "),
			)
			dbRows, err := DB.Query(query, args...)
			if err != nil {
				return fmt.Errorf("phase2 db query: %w", err)
			}
			existingSet := map[string]bool{}
			for dbRows.Next() {
				var k, v string
				dbRows.Scan(&k, &v)
				existingSet[k+"@"+v] = true
			}
			dbRows.Close()

			for _, p := range pairs {
				if !existingSet[p.key+"@"+p.version] {
					orphanKeys = append(orphanKeys, p.objectKey)
				}
			}
			for _, objKey := range orphanKeys {
				LogDebug("[cleanup] phase2: deleting R2 orphan %s", objKey)
				if err := R2DeleteObject(world, objKey); err != nil {
					LogError("[cleanup] phase2: r2 delete failed for %s: %v", objKey, err)
				}
			}
		}

		totalOrphans += len(orphanKeys)
		LogInfo("[cleanup] phase2 page %d: scanned %d object(s), deleted %d orphan(s)", page, len(result.Keys), len(orphanKeys))

		if result.NextToken == nil {
			break
		}
		token = result.NextToken
		page++
	}

	LogInfo("[cleanup] done for world=%s: phase2 deleted %d R2 orphan(s)", world, totalOrphans)
	return nil
}
