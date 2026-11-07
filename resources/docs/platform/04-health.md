---
title: System health and alerts
section: Operations
order: 40
---
Team > System health shows a minute-by-minute record (kept 30 days): candidates writing, answers saved, requests, response time (95th percentile), server errors, background jobs, CPU, memory, disk and database connections.

**Alerts** open when a figure stays past its threshold (for example CPU over 85% for 5 minutes, errors over 2% with real traffic, disk under 15% free, the scheduler stopped, no backup in 26 hours, a hall device down) and close when it recovers. Each is sent to every platform administrator in-app and by e-mail, and to `PLATFORM_ALERT_WEBHOOK` (Slack, Teams or Google Chat) if set. Press *I'm on it* so others know.

The figures need the scheduler running every minute (`php artisan schedule:work`, or the Windows task in the operations guide).
