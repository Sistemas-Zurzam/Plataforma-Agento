import { BankOutlined } from '@ant-design/icons';
import { Select } from 'antd';
import { useEffect, useState } from 'react';
import { useEmpresas } from '../hooks/useEmpresas';
import EmpresaReglasTardanza from './EmpresaReglasTardanza';

/**
 * Vista global de la misma configuración que ya existe empresa por empresa
 * en el modal "Editar empresa" → pestaña Asistencia — reutiliza el mismo
 * componente y hook, solo agrega el selector de empresa. No hay lógica
 * duplicada, ambos puntos de entrada leen/escriben el mismo backend.
 */
export default function PoliticasTardanzaGlobal({ user }) {
  const { empresas, fetchEmpresas } = useEmpresas();
  const [empresaId, setEmpresaId] = useState(null);

  useEffect(() => {
    fetchEmpresas().then((lista) => {
      if (lista.length > 0) setEmpresaId(lista[0].id);
    });
  }, [fetchEmpresas]);

  const empresaSeleccionada = empresas.find((e) => e.id === empresaId);

  return (
    <div>
      <p className="mb-4 text-sm text-gray-500">
        Descuento por minuto de tardanza. Las áreas pueden tener excepciones que reemplazan la
        política general.
      </p>

      <div className="mb-4 flex items-center gap-2">
        <BankOutlined className="text-gray-400" />
        <Select
          value={empresaId}
          onChange={setEmpresaId}
          className="w-64"
          placeholder="Selecciona una empresa"
          options={empresas.map((empresa) => ({ value: empresa.id, label: empresa.nombre }))}
        />
      </div>

      {empresaSeleccionada && (
        <EmpresaReglasTardanza
          empresaId={empresaSeleccionada.id}
          empresaNombre={empresaSeleccionada.nombre}
          user={user}
        />
      )}
    </div>
  );
}
