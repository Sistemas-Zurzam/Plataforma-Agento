export function agruparParametros(parametros) {
  const grupos = [];
  const indicePorGrupo = {};

  parametros.forEach((parametro) => {
    if (!(parametro.grupo in indicePorGrupo)) {
      indicePorGrupo[parametro.grupo] = grupos.length;
      grupos.push({ nombre: parametro.grupo, parametros: [] });
    }
    grupos[indicePorGrupo[parametro.grupo]].parametros.push(parametro);
  });

  return grupos;
}
