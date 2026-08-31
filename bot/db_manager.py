"""
db_manager.py - Módulo de conexión a MySQL para el Bot
Conecta a la base de datos fieles_db y permite:
  - Obtener cédulas pendientes (sin puesto_votacion)
  - Guardar puesto y mesa extraídos de cualquier fuente
"""

import pymysql

from config import DB_CONFIG

def conectar():
    """Crea y devuelve una conexión a MySQL."""
    cfg = DB_CONFIG.copy()
    cfg["cursorclass"] = pymysql.cursors.DictCursor
    return pymysql.connect(**cfg)

def obtener_cedulas_pendientes(limite=20):
    """
    Busca en la tabla referidos las cédulas que NO tienen 
    puesto_votacion registrado.
    Retorna una lista de diccionarios con id, cedula y nombre.
    """
    conn = conectar()
    try:
        with conn.cursor() as cur:
            cur.execute("""
                SELECT id, cedula, nombres, apellidos,
                       CONCAT(nombres, ' ', apellidos) AS nombre_completo
                FROM referidos 
                WHERE (puesto_votacion IS NULL OR puesto_votacion = '')
                ORDER BY id ASC
                LIMIT %s
            """, (limite,))
            return cur.fetchall()
    finally:
        conn.close()

def guardar_lugar_votacion(cedula, departamento, municipio, puesto, direccion, mesa):
    """
    Actualiza la tabla referidos con TODOS los campos extraídos de la Registraduría:
    departamento, municipio, puesto_votacion, direccion_votacion, mesa_votacion, votante_yopal
    """
    # Determinar si vota en Yopal según el municipio extraído
    vota_yopal = "Si" if municipio.strip().upper() == "YOPAL" else "No"

    conn = conectar()
    try:
        with conn.cursor() as cur:
            cur.execute("""
                UPDATE referidos 
                SET departamento      = %s,
                    municipio         = %s,
                    puesto_votacion   = %s,
                    direccion_votacion= %s,
                    mesa_votacion     = %s,
                    votante_yopal     = %s
                WHERE cedula = %s
            """, (departamento, municipio, puesto, direccion, mesa, vota_yopal, cedula))
            conn.commit()
            actualizado = cur.rowcount > 0
            if actualizado:
                print(f"  [✓] CC {cedula} → {municipio} | {puesto} | Mesa: {mesa} | Yopal: {vota_yopal}")
            else:
                print(f"  [!] CC {cedula} no se encontró en la tabla referidos.")
            return actualizado
    finally:
        conn.close()


def ver_resumen():
    """Muestra un resumen rápido del estado de la tabla referidos."""
    conn = conectar()
    try:
        with conn.cursor() as cur:
            cur.execute("SELECT COUNT(*) AS total FROM referidos")
            total = cur.fetchone()['total']
            
            cur.execute("""
                SELECT COUNT(*) AS pendientes FROM referidos 
                WHERE puesto_votacion IS NULL OR puesto_votacion = ''
            """)
            pendientes = cur.fetchone()['pendientes']
            
            completados = total - pendientes
            
            print("\n========= RESUMEN BASE DE DATOS =========")
            print(f"  Total referidos:      {total}")
            print(f"  Con puesto y mesa:    {completados}")
            print(f"  Pendientes por llenar:{pendientes}")
            print("==========================================\n")
            
            return {"total": total, "completados": completados, "pendientes": pendientes}
    finally:
        conn.close()


# --- PRUEBA RÁPIDA ---
if __name__ == "__main__":
    print("[*] Probando conexión a la base de datos fieles_db...")
    
    # 1. Mostrar resumen
    ver_resumen()
    
    # 2. Mostrar las primeras 5 cédulas pendientes
    pendientes = obtener_cedulas_pendientes(limite=5)
    if pendientes:
        print("[*] Primeras 5 cédulas sin puesto/mesa registrado:")
        for p in pendientes:
            print(f"    CC: {p['cedula']} - {p['nombre_completo']}")
    else:
        print("[+] ¡Todas las cédulas ya tienen puesto y mesa!")
    
    # 3. Ejemplo de cómo guardar (COMENTADO para que no escriba datos falsos)
    # guardar_lugar_votacion("1118539661", "CASANARE", "YOPAL", "IE EL PARAISO", "CRA 7 No 30-01", "20")
