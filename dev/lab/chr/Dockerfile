FROM debian:12-slim
RUN apt-get update && apt-get install -y --no-install-recommends \
    qemu-system-x86 qemu-utils iproute2 python3 python3-pexpect python3-paramiko curl ca-certificates unzip \
    && rm -rf /var/lib/apt/lists/*
# Official raw image, pinned version and checksum; no third-party RouterOS image.
RUN curl --fail --location --retry 3 https://download.mikrotik.com/routeros/7.24.4/chr-7.24.4.img.zip -o /tmp/chr.zip \
    && echo 'dd678564de45eb7ae27b22f5fb4bd45a67970aed842e1cea96f5dd018d7b00ae  /tmp/chr.zip' | sha256sum -c - \
    && unzip -p /tmp/chr.zip chr-7.24.4.img > /opt/chr.img && rm /tmp/chr.zip
COPY lab/chr/ /opt/lab/
CMD ["python3", "/opt/lab/start.py"]
