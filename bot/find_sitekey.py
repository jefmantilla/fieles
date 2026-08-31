import requests
import re

url = "https://consultacenso.registraduria.gov.co/"
headers = {'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)'}
res = requests.get(url, headers=headers)
html = res.text

# Buscar sitekey
match = re.search(r'data-sitekey="([^"]+)"', html)
if match:
    print("SiteKey encontrado:", match.group(1))
else:
    print("No se encontro data-sitekey. Buscando otros patrones...")
    match = re.search(r'sitekey[\"\'\s:=]+([a-zA-Z0-9_-]+)', html)
    if match:
        print("SiteKey encontrado:", match.group(1))
    else:
        print("Definitivamente no se encontro sitekey. Veamos si hay turnstile o hcaptcha.")
        if "turnstile" in html.lower(): print("Usa Cloudflare Turnstile")
        if "hcaptcha" in html.lower(): print("Usa hCaptcha")
        if "recaptcha" in html.lower(): print("Usa reCaptcha")
