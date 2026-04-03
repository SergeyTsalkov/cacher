package internal

import "log"

func LogDebug(format string, args ...any) {
	if Cfg != nil && Cfg.Debug {
		log.Printf("[debug] "+format, args...)
	}
}

func LogInfo(format string, args ...any) {
	log.Printf("[info] "+format, args...)
}

func LogWarn(format string, args ...any) {
	log.Printf("[warn] "+format, args...)
}

func LogError(format string, args ...any) {
	log.Printf("[error] "+format, args...)
}
