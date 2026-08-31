# 🤖 Bot Extractor de Puesto y Mesa de Votación (Registraduría)

Módulo automatizado en Python con **Playwright** e **IA Capsolver** para consultar automáticamente el censo electoral de la Registraduría Nacional y guardar departamento, municipio, puesto, dirección y mesa de votación en la base de datos local `fieles_db`.

---

## ⚙️ Archivos del Bot

- **`config.py`**: Configuración centralizada de MySQL local XAMPP y API Key de Capsolver.
- **`db_manager.py`**: Conexión a MySQL, consulta de cédulas pendientes y actualización de puestos de votación.
- **`extractor_censo.py`**: Motor de navegación invisible (**Playwright**) y resolución de Captcha con IA (**Capsolver**).
- **`ejecutar_bot.py`**: Script interactivo principal para ejecutar el bot indicando la cantidad de cédulas a procesar.
- **`fix_votante_yopal.py`**: Utilidad para sincronizar el estado `votante_yopal = 'Si'` en los registros que ya tienen puesto de votación.

---

## 🚀 Cómo Ejecutar el Bot Localmente

### 1. Verificar el Estado de la Base de Datos
Abre una terminal o PowerShell en la carpeta `c:\xampp\htdocs\Aplicaiones\fieles\bot` y ejecuta:
```bash
python db_manager.py
```
Esto mostrará el resumen de cédulas registradas, cuántas ya tienen puesto/mesa y cuántas están pendientes por consultar.

### 2. Iniciar el Extractor Automático
Para iniciar el bot interactivo:
```bash
python ejecutar_bot.py
```
El bot te preguntará **¿Cuántas cédulas deseas procesar?** (por ejemplo: `10` o `50`), y comenzará la extracción en segundo plano guardando los resultados directamente en tu base de datos XAMPP local.

---

## 📝 Ejemplo de Salida:
```text
========= RESUMEN BASE DE DATOS =========
  Total referidos:      3018
  Con puesto y mesa:    186
  Pendientes por llenar:2832
==========================================

¿Cuántas cédulas deseas procesar? → 5
[*] Se procesarán 5 cédulas en orden.
[*] Resolviendo Captcha con IA...
[+] Captcha resuelto.
[+] Extraído: Puesto=IE EL PARAISO | Mesa=20
  [✓] CC 1118539661 → YOPAL | IE EL PARAISO | Mesa: 20 | Yopal: Si
```
