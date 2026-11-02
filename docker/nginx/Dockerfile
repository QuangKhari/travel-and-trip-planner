# nginx phục vụ file tĩnh trong public/ và chuyển PHP sang container app (PF-03)
FROM nginx:1.27-alpine
RUN rm /etc/nginx/conf.d/default.conf
COPY docker/nginx/default.conf /etc/nginx/conf.d/travela.conf
COPY public /var/www/html/public
RUN rm -rf /var/www/html/public/storage && mkdir -p /var/www/html/public/storage
