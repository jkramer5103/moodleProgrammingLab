# Runner API image. Built from the runner/ directory.
FROM docker:27-cli AS dockercli

FROM python:3.12-slim
ENV PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1 PYTHONPATH=/srv/shared:/srv/app
COPY --from=dockercli /usr/local/bin/docker /usr/local/bin/docker
COPY app/requirements.txt /srv/app/requirements.txt
RUN pip install --no-cache-dir -r /srv/app/requirements.txt
COPY shared/mplcodec.py /srv/shared/
COPY app/mplrunner /srv/app/mplrunner
WORKDIR /srv/app
EXPOSE 8000
HEALTHCHECK --interval=30s --timeout=5s CMD python -c "import urllib.request;urllib.request.urlopen('http://127.0.0.1:8000/v1/health')"
CMD ["uvicorn", "mplrunner.main:app", "--host", "0.0.0.0", "--port", "8000", "--proxy-headers", "--no-server-header"]
