"""
ejecutar_bot.py - Bot interactivo de actualización de lugar de votación.
Te pregunta cuántas cédulas procesar y las ejecuta una por una.
"""

import asyncio
from db_manager import obtener_cedulas_pendientes, guardar_lugar_votacion, ver_resumen
from extractor_censo import consultar_registraduria_auto


async def main():
    print("\n" + "=" * 60)
    print("   BOT DE ACTUALIZACIÓN DE LUGAR DE VOTACIÓN")
    print("=" * 60)
    
    # Mostrar estado actual
    ver_resumen()
    
    # Preguntar cuántas procesar
    cantidad = input("¿Cuántas cédulas deseas procesar? → ")
    
    try:
        cantidad = int(cantidad)
    except:
        print("Número inválido. Saliendo.")
        return
    
    # Obtener pendientes
    pendientes = obtener_cedulas_pendientes(limite=cantidad)
    
    if not pendientes:
        print("[+] ¡No hay cédulas pendientes! Todas ya tienen puesto y mesa.")
        return
    
    print(f"\n[*] Se procesarán {len(pendientes)} cédulas en orden.\n")
    
    exitosas = 0
    fallidas = 0
    
    for i, persona in enumerate(pendientes, 1):
        cedula = persona['cedula']
        nombre = persona['nombre_completo']
        
        print(f"\n{'─' * 55}")
        print(f"  [{i}/{len(pendientes)}] {nombre}")
        print(f"  Cédula: {cedula}")
        print(f"{'─' * 55}")
        
        try:
            resultado = await consultar_registraduria_auto(cedula)
            
            if resultado and resultado.get("status") == "ok":
                guardar_lugar_votacion(
                    cedula=cedula,
                    departamento=resultado.get("departamento", ""),
                    municipio=resultado.get("municipio", ""),
                    puesto=resultado.get("puesto", ""),
                    direccion=resultado.get("direccion", ""),
                    mesa=resultado.get("mesa", "")
                )
                exitosas += 1
            else:
                motivo = resultado.get("mensaje", "Sin datos") if resultado else "Sin respuesta"
                print(f"  [✗] Sin datos: {motivo}")
                fallidas += 1
                
        except Exception as e:
            print(f"  [✗] Error: {e}")
            fallidas += 1
        
        # Pausa entre consultas
        if i < len(pendientes):
            print(f"  [~] Esperando 5 segundos...")
            await asyncio.sleep(5)
    
    # Resumen final
    print(f"\n{'=' * 55}")
    print(f"  LISTO  ✓ {exitosas} guardadas  |  ✗ {fallidas} fallidas")
    print(f"{'=' * 55}")
    ver_resumen()


if __name__ == "__main__":
    asyncio.run(main())
