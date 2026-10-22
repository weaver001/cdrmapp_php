#!/usr/bin/env python3
"""Run the pinned Gradle wrapper with cloud proxy trust and writable caches."""
import os,pathlib,re,shutil,subprocess,sys,urllib.parse
def configure():
 root=pathlib.Path(__file__).resolve().parents[1]
 cache=pathlib.Path(os.environ.get('CDRMAPP_CACHE','/workspace/.cache'))
 env=os.environ.copy()
 jdk=cache/'cdrmapp-jdk'
 if (jdk/'bin/javac').exists(): env['JAVA_HOME']=str(jdk)
 java=pathlib.Path(env.get('JAVA_HOME','/usr/lib/jvm/java-21-openjdk-amd64'))
 store=cache/'cdrmapp-java-cacerts'
 if not store.exists():
  shutil.copyfile(java/'lib/security/cacerts',store)
  certificate=os.environ.get('CODEX_PROXY_CERT') or os.environ.get('SSL_CERT_FILE')
  if certificate and pathlib.Path(certificate).is_file():
   certs=re.findall(r'-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----',pathlib.Path(certificate).read_text(),re.S)
   for n,cert in enumerate(certs):
    p=cache/f'cloud-java-{n}.pem';p.write_text(cert)
    subprocess.run([str(java/'bin/keytool'),'-importcert','-noprompt','-trustcacerts','-alias',f'cloud-{n}','-file',str(p),'-keystore',str(store),'-storepass','changeit'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,check=True)
 proxy=urllib.parse.urlparse(env.get('HTTPS_PROXY',''))
 opts=[f'-Djavax.net.ssl.trustStore={store}','-Djavax.net.ssl.trustStorePassword=changeit']
 if proxy.hostname:opts += ['-Dhttps.proxyHost='+proxy.hostname,'-Dhttps.proxyPort='+str(proxy.port or 80),'-Dhttp.proxyHost='+proxy.hostname,'-Dhttp.proxyPort='+str(proxy.port or 80)]
 env['GRADLE_USER_HOME']=str(cache/'cdrmapp-gradle')
 env['ANDROID_HOME']=str(cache/'android-sdk')
 env['ANDROID_USER_HOME']=str(cache/'android-user')
 return root,env,opts

if __name__=='__main__':
 root,env,opts=configure()
 args=sys.argv[1:] or [':app:assembleDebug',':app:testDebugUnitTest',':app:lintDebug','--no-daemon']
 raise SystemExit(subprocess.run([str(root/'android/gradlew')]+opts+args,cwd=root/'android',env=env).returncode)
