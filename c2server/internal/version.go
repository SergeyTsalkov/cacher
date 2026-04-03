package internal

import (
	"regexp"
	"strconv"
)

var versionSplitter = regexp.MustCompile(`[.\-_+]`)

type segment struct {
	n   int
	s   string
	num bool
}

func parseVersion(v string) []segment {
	parts := versionSplitter.Split(v, -1)
	segs := make([]segment, len(parts))
	for i, p := range parts {
		if n, err := strconv.Atoi(p); err == nil {
			segs[i] = segment{n: n, num: true}
		} else {
			segs[i] = segment{s: p, num: false}
		}
	}
	return segs
}

// VersionCompare mirrors PHP's version_compare().
// Returns negative if a < b, positive if a > b, 0 if equal.
func VersionCompare(a, b string) int {
	pa := parseVersion(a)
	pb := parseVersion(b)
	length := len(pa)
	if len(pb) > length {
		length = len(pb)
	}
	for i := 0; i < length; i++ {
		var x, y segment
		if i < len(pa) {
			x = pa[i]
		} else {
			x = segment{n: 0, num: true}
		}
		if i < len(pb) {
			y = pb[i]
		} else {
			y = segment{n: 0, num: true}
		}

		if x.num && y.num {
			if x.n != y.n {
				return x.n - y.n
			}
		} else {
			xs := x.s
			if x.num {
				xs = strconv.Itoa(x.n)
			}
			ys := y.s
			if y.num {
				ys = strconv.Itoa(y.n)
			}
			if xs < ys {
				return -1
			}
			if xs > ys {
				return 1
			}
		}
	}
	return 0
}

type VersionRow struct {
	Version   string `json:"version"`
	CreatedAt int64  `json:"created_at"`
}

func LatestVersion(rows []VersionRow) *VersionRow {
	if len(rows) == 0 {
		return nil
	}
	best := &rows[0]
	for i := 1; i < len(rows); i++ {
		if VersionCompare(rows[i].Version, best.Version) > 0 {
			best = &rows[i]
		}
	}
	return best
}

func SortVersionsDesc(rows []VersionRow) []VersionRow {
	sorted := make([]VersionRow, len(rows))
	copy(sorted, rows)
	for i := 1; i < len(sorted); i++ {
		for j := i; j > 0 && VersionCompare(sorted[j].Version, sorted[j-1].Version) > 0; j-- {
			sorted[j], sorted[j-1] = sorted[j-1], sorted[j]
		}
	}
	return sorted
}
