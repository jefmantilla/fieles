import pymysql

conn = pymysql.connect(host='localhost', user='root', password='', database='fieles_db', charset='utf8mb4')
cur = conn.cursor()

# Corregir los registros que ya tienen puesto guardado pero votante_yopal no fue actualizado
cur.execute("UPDATE referidos SET votante_yopal = 'Si' WHERE puesto_votacion IS NOT NULL AND puesto_votacion != ''")
conn.commit()
print(f"[+] Registros corregidos: {cur.rowcount}")

# Verificar
cur.execute("SELECT cedula, nombres, puesto_votacion, mesa_votacion, votante_yopal FROM referidos WHERE puesto_votacion IS NOT NULL AND puesto_votacion != '' LIMIT 10")
rows = cur.fetchall()
print("\n[*] Muestra de registros actualizados:")
for r in rows:
    print(f"  CC: {r[0]} | {r[1]} | {r[2]} | Mesa: {r[3]} | Vota Yopal: {r[4]}")

conn.close()
