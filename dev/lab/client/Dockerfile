FROM alpine:3.23
RUN apk add --no-cache firefox geckodriver xvfb fluxbox xterm x11vnc novnc \
    iproute2 iptables curl dbus ttf-dejavu font-noto python3 su-exec \
    && adduser -D -u 1000 client
COPY lab/client/ /opt/lab/
RUN chmod 755 /opt/lab/start.sh /opt/lab/dhcp.sh
ENV DISPLAY=:99 HOME=/home/client
CMD ["/opt/lab/start.sh"]
