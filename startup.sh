#!/bin/bash
cat > /etc/nginx/sites-available/default << 'EOF'
server {
    listen 8080;
    root /home/site/wwwroot;
    index index.php;

    location / {
        try_files $uri $uri/ @router;
    }

    location @router {
        rewrite ^/([a-zA-Z0-9_-]+)/?$ /router.php?slug=$1 last;
    }

    location ~ \.php$ {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
EOF
service nginx reload

# ── Subscription Expiry Cron (runs daily at 8:00 AM UTC / 4:00 PM PHT) ──
echo "0 8 * * * root php /home/site/wwwroot/subscription_cron.php --cron >> /home/site/wwwroot/cron.log 2>&1" > /etc/cron.d/pawnhub_subscription
chmod 0644 /etc/cron.d/pawnhub_subscription
service cron start