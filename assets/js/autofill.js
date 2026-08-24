/**
 * Script de Autocompletado y Asistencia de Registro Móvil en Campo
 * (Permite registrar múltiples personas distintas desde el mismo dispositivo móvil)
 */

document.addEventListener('DOMContentLoaded', function () {
    const regForm = document.getElementById('formRegistroReferido');

    if (regForm) {
        // Cargar borrador previo de localStorage si la página fue recargada por error
        const savedCedula = localStorage.getItem('draft_cedula');
        const savedNombres = localStorage.getItem('draft_nombres');
        const savedApellidos = localStorage.getItem('draft_apellidos');
        const savedCorreo = localStorage.getItem('draft_correo');
        const savedCelular = localStorage.getItem('draft_celular');
        const savedComuna = localStorage.getItem('draft_comuna');
        const savedVotante = localStorage.getItem('draft_votante_yopal');

        if (savedCedula && document.getElementById('cedula')) document.getElementById('cedula').value = savedCedula;
        if (savedNombres && document.getElementById('nombres')) document.getElementById('nombres').value = savedNombres;
        if (savedApellidos && document.getElementById('apellidos')) document.getElementById('apellidos').value = savedApellidos;
        if (savedCorreo && document.getElementById('correo')) document.getElementById('correo').value = savedCorreo;
        if (savedCelular && document.getElementById('celular')) document.getElementById('celular').value = savedCelular;
        if (savedComuna && document.getElementById('comuna')) document.getElementById('comuna').value = savedComuna;
        if (savedVotante && document.getElementById('votante_yopal')) document.getElementById('votante_yopal').value = savedVotante;

        // Auto-guardar borrador mientras digitan los datos en el celular
        const inputs = regForm.querySelectorAll('input, select');
        inputs.forEach(input => {
            input.addEventListener('input', function () {
                if (this.id) {
                    localStorage.setItem('draft_' + this.id, this.value);
                }
            });
        });
    }
});

function limpiarBorradores() {
    localStorage.removeItem('draft_cedula');
    localStorage.removeItem('draft_nombres');
    localStorage.removeItem('draft_apellidos');
    localStorage.removeItem('draft_correo');
    localStorage.removeItem('draft_celular');
    localStorage.removeItem('draft_comuna');
    localStorage.removeItem('draft_votante_yopal');
}
