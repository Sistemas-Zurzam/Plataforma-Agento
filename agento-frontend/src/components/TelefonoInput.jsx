import { Input, Select } from 'antd';
import { CODIGOS_PAIS } from '../constants/codigosPais';

const CODIGO_DEFECTO = '+51';

/**
 * Selector de código de país (buscable, escribir filtra por código o
 * nombre) + número, combinados en un solo valor de texto ("+51 999999999")
 * para no requerir una columna nueva en backend — es solo un dato de
 * contacto, no algo que se necesite consultar por separado.
 *
 * El separador (espacio) siempre se incluye una vez que se elige un país,
 * incluso sin número todavía — si no, elegir un código antes de escribir el
 * número no tenía dónde guardarse (se colapsaba a "" y el selector volvía
 * a mostrar el código por defecto).
 */
export default function TelefonoInput({ value, onChange, placeholder, disabled }) {
  const espacio = value ? value.indexOf(' ') : -1;
  const codigo = espacio === -1 ? (value || CODIGO_DEFECTO) : value.slice(0, espacio);
  const numero = espacio === -1 ? '' : value.slice(espacio + 1);

  const actualizar = (nuevoCodigo, nuevoNumero) => {
    const esVacio = !nuevoNumero && nuevoCodigo === CODIGO_DEFECTO;
    onChange(esVacio ? '' : `${nuevoCodigo} ${nuevoNumero}`);
  };

  return (
    <div className="flex gap-1">
      <Select
        style={{ width: 88, flex: '0 0 auto' }}
        popupMatchSelectWidth={220}
        showSearch
        disabled={disabled}
        value={codigo}
        onChange={(nuevoCodigo) => actualizar(nuevoCodigo, numero)}
        optionLabelProp="value"
        filterOption={(input, option) => option.label.toLowerCase().includes(input.toLowerCase())}
        options={CODIGOS_PAIS}
      />
      <Input
        style={{ flex: '1 1 auto', minWidth: 0 }}
        disabled={disabled}
        value={numero}
        onChange={(e) => actualizar(codigo, e.target.value)}
        placeholder={placeholder}
      />
    </div>
  );
}
