"""
extractor_censo.py - Función de extracción de datos de lugar de votación.
Usa Capsolver para resolver el Captcha y Playwright para navegar.
Devuelve un diccionario con los datos extraídos.
"""

import asyncio
import capsolver
from playwright.async_api import async_playwright
from bs4 import BeautifulSoup

from config import CAPSOLVER_API_KEY, REGISTRADURIA_URL, REGISTRADURIA_SITEKEY

# Configurar API Key de Capsolver
capsolver.api_key = CAPSOLVER_API_KEY


async def consultar_registraduria_auto(cedula):
    """
    Consulta una cédula y devuelve un diccionario con los datos extraídos.
    Retorna: {"status": "ok", "departamento": ..., "municipio": ..., "puesto": ..., "direccion": ..., "mesa": ...}
    O en caso de error: {"status": "error", "mensaje": "..."}
    """
    
    # 1. Resolver el Captcha con Capsolver (con reintentos automáticos)
    MAX_INTENTOS = 3
    token_captcha = None
    
    for intento in range(1, MAX_INTENTOS + 1):
        try:
            print(f"  [*] Resolviendo Captcha con IA... (Intento {intento}/{MAX_INTENTOS})")
            loop = asyncio.get_event_loop()
            
            def solve_captcha():
                return capsolver.solve({
                    "type": "ReCaptchaV2TaskProxyless",
                    "websiteURL": REGISTRADURIA_URL,
                    "websiteKey": REGISTRADURIA_SITEKEY,
                })
            
            solucion = await loop.run_in_executor(None, solve_captcha)
            token_captcha = solucion['gRecaptchaResponse']
            print(f"  [+] Captcha resuelto en el intento {intento}.")
            break  # Salir del loop si tuvo éxito
            
        except Exception as e:
            print(f"  [-] Intento {intento} falló: {e}")
            if intento < MAX_INTENTOS:
                print(f"  [~] Reintentando en 5 segundos...")
                await asyncio.sleep(5)
            else:
                print(f"  [✗] Capsolver falló {MAX_INTENTOS} veces. Saltando esta cédula.")
                return {"status": "error", "mensaje": f"Capsolver falló tras {MAX_INTENTOS} intentos: {e}"}

    if not token_captcha:
        return {"status": "error", "mensaje": "No se obtuvo token de Captcha"}

    # 2. Abrir navegador e inyectar solución
    async with async_playwright() as p:
        browser = await p.chromium.launch(headless=True)  # headless=True = navegador INVISIBLE
        context = await browser.new_context(
            user_agent="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36"
        )
        page = await context.new_page()

        try:
            await page.goto("https://consultacenso.registraduria.gov.co/", wait_until="networkidle")
            await page.fill("input#documento", str(cedula))
            
            # Inyectar token del Captcha
            await page.evaluate(f"document.getElementById('g-recaptcha-response').innerHTML = '{token_captcha}';")
            try:
                await page.evaluate("___grecaptcha_cfg.clients[0].Y.Y.callback()")
            except:
                pass

            await page.click("button[type='submit']")
            await page.wait_for_timeout(5000)
            
            # Extraer datos
            html_resultado = await page.content()
            soup = BeautifulSoup(html_resultado, 'html.parser')
            texto_pantalla = soup.get_text(separator="\n", strip=True)
            lineas = texto_pantalla.split('\n')
            
            # Buscar los encabezados y extraer valores
            try:
                idx_nuip = lineas.index("NUIP")
                idx_mesa = lineas.index("MESA")
                offset = (idx_mesa - idx_nuip) + 1
                
                datos = {
                    "status": "ok",
                    "cedula": lineas[idx_nuip + offset],
                    "departamento": lineas[idx_nuip + offset + 1],
                    "municipio": lineas[idx_nuip + offset + 2],
                    "puesto": lineas[idx_nuip + offset + 3],
                    "direccion": lineas[idx_nuip + offset + 4],
                    "mesa": lineas[idx_nuip + offset + 5],
                }
                
                print(f"  [+] Extraído: Puesto={datos['puesto']} | Mesa={datos['mesa']}")
                return datos
                
            except ValueError:
                return {"status": "error", "mensaje": "Cédula no encontrada en el censo"}
                
        except Exception as e:
            return {"status": "error", "mensaje": str(e)}
        finally:
            await browser.close()


# Prueba individual (solo si ejecutas este archivo directamente)
if __name__ == "__main__":
    resultado = asyncio.run(consultar_registraduria_auto("1118539661"))
    print(resultado)
