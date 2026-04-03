package internal

import "time"

func StartScheduler() {
	go func() {
		time.Sleep(5 * time.Second)
		runScheduled()
		ticker := time.NewTicker(time.Hour)
		for range ticker.C {
			runScheduled()
		}
	}()
	LogInfo("scheduler: started (runs hourly)")
}

func runScheduled() {
	LogInfo("scheduler: starting hourly cleanup + backup")

	for world := range Cfg.Worlds {
		if err := RunCleanup(world); err != nil {
			LogError("scheduler: cleanup failed for world=%s: %v", world, err)
		}
	}

	if err := RunBackup(); err != nil {
		LogError("scheduler: backup failed: %v", err)
	}

	LogInfo("scheduler: done")
}
