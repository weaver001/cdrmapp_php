#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
export DOCKER_CONFIG="${DOCKER_CONFIG:-/workspace/.cache/cdrmapp-docker}"
export CDRMAPP_CACHE="${CDRMAPP_CACHE:-/workspace/.cache}"
mkdir -p "$CDRMAPP_CACHE/cdrmapp-jdk"
if [[ ! -x "$CDRMAPP_CACHE/cdrmapp-jdk/bin/javac" ]]; then
 docker run --rm -u "$(id -u):$(id -g)" -v "$CDRMAPP_CACHE/cdrmapp-jdk:/export" --entrypoint sh eclipse-temurin:21-jdk@sha256:23cc5734ff46e776f51ce66eb9ee08444fd47caabec0a95b7eb6e94be2d3696d -c 'cp -a /opt/java/openjdk/. /export/'
fi
export JAVA_HOME="$CDRMAPP_CACHE/cdrmapp-jdk"
export ANDROID_HOME="$CDRMAPP_CACHE/android-sdk"
export ANDROID_USER_HOME="$CDRMAPP_CACHE/android-user"
if [[ ! -x "$ANDROID_HOME/cmdline-tools/latest/bin/sdkmanager" ]]; then
 curl -fL https://dl.google.com/android/repository/commandlinetools-linux-16111833_latest.zip -o "$CDRMAPP_CACHE/android-commandline.zip"
 python3 - <<'PYCODE'
import hashlib,os,pathlib,zipfile
cache=pathlib.Path(os.environ['CDRMAPP_CACHE']);p=cache/'android-commandline.zip'
# Google's published repository2-1.xml checksum for command-line tools revision 23.
assert hashlib.sha1(p.read_bytes()).hexdigest()=='e025545c62a8e64c7559119566a569fb1dec5f60','Android SDK checksum mismatch'
d=cache/'android-sdk/cmdline-tools';d.mkdir(parents=True,exist_ok=True)
with zipfile.ZipFile(p) as z:z.extractall(d)
(d/'cmdline-tools').rename(d/'latest')
for f in (d/'latest/bin').iterdir():f.chmod(0o755)
PYCODE
fi
# Use the SDK manager's supported system-proxy route; never disable TLS verification.
python3 - <<'PYCODE'
import os,subprocess,pathlib,importlib.util
root=pathlib.Path.cwd();cache=pathlib.Path(os.environ['CDRMAPP_CACHE'])
spec=importlib.util.spec_from_file_location('cdrm_gradle',root/'infra/gradle-cloud.py')
module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
_,env,opts=module.configure()
env['JAVA_TOOL_OPTIONS']=' '.join(opts)
if not (cache/'android-sdk/platforms/android-36/android.jar').exists() or not (cache/'android-sdk/build-tools/35.0.0').exists():
 cmd=[str(cache/'android-sdk/cmdline-tools/latest/bin/sdkmanager'),'--sdk_root='+str(cache/'android-sdk'),'platform-tools','platforms;android-36','build-tools;35.0.0']
 result=subprocess.run(cmd,input='y\n'*20,text=True,env=env)
 if result.returncode:raise SystemExit(result.returncode)
PYCODE
python3 infra/gradle-cloud.py :app:assembleDebug :app:testDebugUnitTest :app:lintDebug --no-daemon
