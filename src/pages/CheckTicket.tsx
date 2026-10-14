import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { ArrowLeft, Search, Clock, CheckCircle, AlertTriangle, XCircle, MessageCircle } from 'lucide-react';
import { useData, Ticket } from '../contexts/DataContext';

const statusConfig: Record<string, { label: string; color: string; icon: React.ReactNode }> = {
  open: { label: 'Abierto', color: 'bg-blue-100 text-blue-700', icon: <Clock className="w-4 h-4" /> },
  in_progress: { label: 'En Progreso', color: 'bg-yellow-100 text-yellow-700', icon: <AlertTriangle className="w-4 h-4" /> },
  resolved: { label: 'Resuelto', color: 'bg-green-100 text-green-700', icon: <CheckCircle className="w-4 h-4" /> },
  closed: { label: 'Cerrado', color: 'bg-gray-100 text-gray-700', icon: <XCircle className="w-4 h-4" /> },
};

const priorityConfig: Record<string, { label: string; color: string }> = {
  low: { label: 'Baja', color: 'bg-green-100 text-green-700' },
  medium: { label: 'Media', color: 'bg-yellow-100 text-yellow-700' },
  high: { label: 'Alta', color: 'bg-orange-100 text-orange-700' },
  critical: { label: 'Crítica', color: 'bg-red-100 text-red-700' },
};

export default function CheckTicket() {
  const [ticketNumber, setTicketNumber] = useState('');
  const [foundTicket, setFoundTicket] = useState<Ticket | null>(null);
  const [notFound, setNotFound] = useState(false);
  const { tickets } = useData();
  const navigate = useNavigate();

  const handleSearch = (e: React.FormEvent) => {
    e.preventDefault();
    const ticket = tickets.find(t => t.ticketNumber.toLowerCase() === ticketNumber.toLowerCase().trim());
    if (ticket) {
      setFoundTicket(ticket);
      setNotFound(false);
    } else {
      setFoundTicket(null);
      setNotFound(true);
    }
  };

  return (
    <div className="min-h-screen bg-gradient-to-br from-blue-50 to-indigo-50 py-8 px-4">
      <div className="max-w-3xl mx-auto">
        <button
          onClick={() => navigate('/')}
          className="flex items-center text-gray-600 hover:text-gray-800 mb-6"
        >
          <ArrowLeft className="w-5 h-5 mr-1" /> Volver al inicio
        </button>

        <div className="bg-white rounded-2xl shadow-xl p-8 mb-6">
          <h2 className="text-2xl font-bold text-gray-800 mb-2">Consultar Estado de Ticket</h2>
          <p className="text-gray-500 mb-6">Revisa el progreso de tu solicitud ingresando el número asignado.</p>

          <form onSubmit={handleSearch} className="flex gap-3">
            <div className="relative flex-1">
              <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 w-5 h-5" />
              <input
                type="text"
                value={ticketNumber}
                onChange={(e) => setTicketNumber(e.target.value)}
                className="w-full pl-10 pr-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                placeholder="Ej: TK-001"
                required
              />
            </div>
            <button
              type="submit"
              className="bg-blue-600 hover:bg-blue-700 text-white font-medium px-6 py-3 rounded-lg transition-colors"
            >
              Consultar
            </button>
          </form>
        </div>

        {notFound && (
          <div className="bg-red-50 border border-red-200 rounded-xl p-6 text-center">
            <XCircle className="w-12 h-12 text-red-400 mx-auto mb-3" />
            <h3 className="text-lg font-semibold text-red-700">Ticket no encontrado</h3>
            <p className="text-red-600 text-sm mt-1">Verifica el número de ticket e intenta nuevamente.</p>
          </div>
        )}

        {foundTicket && (
          <div className="bg-white rounded-2xl shadow-xl p-8">
            <div className="flex items-center justify-between mb-6">
              <div>
                <h3 className="text-xl font-bold text-gray-800">{foundTicket.subject}</h3>
                <p className="text-gray-500 text-sm">Ticket: {foundTicket.ticketNumber}</p>
              </div>
              <div className={`flex items-center gap-1 px-3 py-1 rounded-full text-sm font-medium ${statusConfig[foundTicket.status].color}`}>
                {statusConfig[foundTicket.status].icon}
                {statusConfig[foundTicket.status].label}
              </div>
            </div>

            <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
              <div className="bg-gray-50 rounded-lg p-3">
                <p className="text-xs text-gray-500">Categoría</p>
                <p className="font-medium text-gray-800">{foundTicket.category}</p>
              </div>
              <div className="bg-gray-50 rounded-lg p-3">
                <p className="text-xs text-gray-500">Prioridad</p>
                <span className={`text-xs px-2 py-1 rounded-full font-medium ${priorityConfig[foundTicket.priority].color}`}>
                  {priorityConfig[foundTicket.priority].label}
                </span>
              </div>
              <div className="bg-gray-50 rounded-lg p-3">
                <p className="text-xs text-gray-500">Creado</p>
                <p className="font-medium text-gray-800">{foundTicket.createdAt}</p>
              </div>
              <div className="bg-gray-50 rounded-lg p-3">
                <p className="text-xs text-gray-500">Actualizado</p>
                <p className="font-medium text-gray-800">{foundTicket.updatedAt}</p>
              </div>
            </div>

            <div className="border-t pt-6">
              <h4 className="font-semibold text-gray-800 mb-4 flex items-center">
                <MessageCircle className="w-5 h-5 mr-2 text-blue-600" />
                Historial de mensajes
              </h4>
              <div className="space-y-3">
                {foundTicket.messages.filter(m => !m.isInternal).map(msg => (
                  <div key={msg.id} className="bg-gray-50 rounded-lg p-4">
                    <div className="flex items-center justify-between mb-2">
                      <span className="font-medium text-gray-800">{msg.author}</span>
                      <span className="text-xs text-gray-500">{msg.createdAt}</span>
                    </div>
                    <p className="text-gray-600 text-sm">{msg.content}</p>
                  </div>
                ))}
              </div>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
