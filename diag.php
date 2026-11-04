<?php
header('Content-Type: text/plain');
echo shell_exec('cd /app && git branch -a 2>&1; echo "--- last commits"; git log --oneline -8 2>&1; echo "--- tracked db/sql files"; git ls-files | grep -iE "database|\.sql"; echo "--- .gitignore"; grep -n -iE "sql|config|database|env" .gitignore; echo "--- .dockerignore"; cat .dockerignore 2>&1 | head -20');
