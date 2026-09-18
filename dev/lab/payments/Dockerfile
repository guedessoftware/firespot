FROM python:3.12-alpine
COPY lab/payments/payments.py /opt/lab/payments.py
RUN mkdir /data && chmod 700 /data
CMD ["python3", "/opt/lab/payments.py"]
