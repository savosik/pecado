#!/usr/bin/env bash
# Создаёт топик Agent Hub на проде из topic-ship-together.md (первая строка — название, остальное — постановка).
# Запуск: bash docs/pickup/agent-hub/create-topic.sh
set -euo pipefail
cd "$(dirname "$0")"
TITLE_B64=$(head -n1 topic-ship-together.md | base64 -w0)
BODY_B64=$(tail -n +3 topic-ship-together.md | base64 -w0)
ssh -o ConnectTimeout=20 ladmin@93.94.150.16 "cd /srv/pecado && docker compose exec -T app php artisan tinker --execute='
\$admin = \App\Models\User::role(\"super-admin\")->orderBy(\"id\")->first();
\$t = \App\Models\AgentTopic::create([\"title\" => trim(base64_decode(\"$TITLE_B64\")), \"task_body\" => trim(base64_decode(\"$BODY_B64\")), \"created_by\" => \$admin?->id]);
echo \"topic_id=\", \$t->id, PHP_EOL;
echo \"админка:  https://pecado.ru/admin/agent-topics/\", \$t->id, PHP_EOL;
echo \"сайт:     https://pecado.ru/api/agent-hub/\", \$t->site_token, PHP_EOL;
echo \"1С:       https://pecado.ru/api/agent-hub/\", \$t->erp_token, PHP_EOL;
'"
