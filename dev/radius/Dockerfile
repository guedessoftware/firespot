FROM ubuntu:22.04
ENV DEBIAN_FRONTEND=noninteractive
RUN apt-get update \
    && apt-get install -y --no-install-recommends freeradius freeradius-mysql freeradius-utils python3 ca-certificates tzdata \
    && rm -rf /var/lib/apt/lists/*
COPY radius/configure.py radius/start.py radius/site.conf radius/health.sh /opt/firespot-radius/
RUN chmod 755 /opt/firespot-radius/health.sh
EXPOSE 1812/udp 1813/udp
CMD ["python3", "/opt/firespot-radius/start.py"]
