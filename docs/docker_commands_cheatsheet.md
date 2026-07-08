# App upgrade/reload after code changes
docker exec -u www-data master-nextcloud-1 php occ app:enable educai && docker exec -u www-data master-nextcloud-1 php occ upgrade --no-interaction

# View logs
docker exec -u www-data master-nextcloud-1 tail -n 300 /var/www/html/data/nextcloud.log | grep -A 5 -B 5 "educai"

# Database queries
docker exec master-database-mysql-1 mysql -u nextcloud -pnextcloud nextcloud -e "DESCRIBE oc_educai_bot_sources;"
docker exec master-database-mysql-1 mysql -u nextcloud -pnextcloud nextcloud -e "SELECT id, bot_id, status, progress, progress_stage, error_message FROM oc_educai_bot_sources;"
docker exec master-database-mysql-1 mysql -u nextcloud -pnextcloud nextcloud -e "SELECT id, class, last_run, argument FROM oc_jobs WHERE class LIKE '%EducAI%';"

# IMPORTANT: Run cron manually (required for RAG reindexing to process!)
docker exec -u www-data master-nextcloud-1 php /var/www/html/cron.php

# Check background jobs
docker exec -u www-data master-nextcloud-1 php occ background-job:list | grep -i educai

# For automatic cron, add to system crontab:
# */5 * * * * docker exec -u www-data master-nextcloud-1 php /var/www/html/cron.php
