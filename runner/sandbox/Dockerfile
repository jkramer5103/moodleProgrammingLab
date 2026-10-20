# Sandbox image: executes submitted code. Built from the runner/ directory.
FROM python:3.12-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    MPLBACKEND=Agg \
    MPLCONFIGDIR=/opt/mplconfig \
    LANG=C.UTF-8 \
    OPENBLAS_NUM_THREADS=1 \
    OMP_NUM_THREADS=1 \
    MKL_NUM_THREADS=1

RUN pip install --no-cache-dir "matplotlib==3.9.*" "numpy==2.1.*" \
 && groupadd -g 1000 student \
 && useradd -u 1000 -g 1000 -M -d /tmp -s /usr/sbin/nologin student \
 && mkdir -p /opt/mpl /opt/mplconfig \
 && python -c "import matplotlib.pyplot as plt; plt.figure(); plt.plot([1,2]); plt.savefig('/tmp/x.png')" \
 && rm -f /tmp/x.png \
 && chmod -R a+rX /opt/mplconfig

COPY shared/mplcodec.py sandbox/harness.py /opt/mpl/

WORKDIR /tmp
ENTRYPOINT ["python", "-I", "-X", "utf8", "/opt/mpl/harness.py"]
