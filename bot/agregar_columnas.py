import pymysql

conn = pymysql.connect(host='localhost', user='root', password='', database='fieles_db', charset='utf8mb4')
cur = conn.cursor()

print("[*] Agregando columnas faltantes a la tabla referidos...")

alteraciones = [
    "ALTER TABLE referidos ADD COLUMN departamento VARCHAR(100) NULL AFTER votante_yopal",
    "ALTER TABLE referidos ADD COLUMN municipio VARCHAR(100) NULL AFTER departamento",
    "ALTER TABLE referidos ADD COLUMN direccion_votacion VARCHAR(200) NULL AFTER puesto_votacion",
]

for sql in alteraciones:
    try:
        cur.execute(sql)
        conn.commit()
        col = sql.split("ADD COLUMN ")[1].split(" ")[0]
        print(f"  [+] Columna '{col}' agregada correctamente.")
    except Exception as e:
        if "Duplicate column" in str(e):
            col = sql.split("ADD COLUMN ")[1].split(" ")[0]
            print(f"  [~] Columna '{col}' ya existe, se omite.")
        else:
            print(f"  [-] Error: {e}")

print("\n[*] Estructura final de la tabla referidos:")
cur.execute("DESCRIBE referidos")
for r in cur.fetchall():
    print(f"  {r[0]:30} {r[1]:20} {r[2]}")

conn.close()
print("\n[+] ¡Listo!")
