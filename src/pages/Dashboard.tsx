import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { LayoutDashboard, LogOut, Plus, MessageCircle, Clock, CheckCircle, AlertTriangle, XCircle, Filter } from 'lucide-react';
import { useAuth } from '../contexts/AuthContext';
import { useData, Ticket } from '../contexts/DataContext';

const statusConfig: Record<string, { label: string; color: string; bg: string }> = {
  open: { label: 'Abierto', color: 'text-blue-700', bg: 'bg-blue-100' },
  in_progress: { label: 'En Progreso', color: 'text-yellow-700', bg: 'bg-yellow-100' },
  resolved: { label: 'Resuelto', color: 'text-green-700', bg: 'bg-green-100' },
  closed: { label: 'Cerrado', color: 'text-gray-700', bg: 'bg-gray-100' },
};

const priorityConfig: Record<string, { label: string; color: string; dot: string }> = {
  low: { label: 'Baja', color: 'text-green-600', dot: 'bg-green-500' },
  medium: { label: 'Media', color: 'text-yellow-600', dot: 'bg-yellow-500' },
  high: { label: 'Alta', color: 'text-orange-600', dot: 'bg-orange-500' },
  critical: { label: 'Crítica', color: 'text-red-600', dot: 'bg-red-500' },
};

export default function Dashboard() {
  const { currentUser, logout } = useAuth();
  const { tickets, users, updateTicket, addTicket, generateTicketNumber, categories } = useData();
  const navigate = useNavigate();
  const [selectedTicket, setSelectedTicket] = useState<Ticket | null>(null);
  const [replyText, setReplyText] = useState('');
  const [showCreateTicket, setShowCreateTicket] = useState(false);
  const [filterStatus, setFilterStatus] = useState('all');
  const [newTicket, setNewTicket] = useState({ subject: '', description: '', category: '', priority: 'medium' as const, assignedTo: '' });

  if (!currentUser) {
    navigate('/login');
    return null;
  }

  const handleLogout = () => {
    logout();
    navigate('/');
  };

  // Get tickets based on role
  let myTickets: Ticket[] = [];
  if (currentUser.role === 'admin') {
    myTickets = tickets;
  } else {
    myTickets = tickets.filter(t => t.assignedTo === currentUser.id);
  }

  const filteredTickets = filterStatus === 'all' ? myTickets : myTickets.filter(t => t.status === filterStatus);

  const handleReply = (ticketId: string) => {
    if (!replyText.trim() || !selectedTicket) return;
    const updatedTicket = {
      ...selectedTicket,
      messages: [...selectedTicket.messages, {
        id: Date.now().toString(),
        author: currentUser.name,
        content: replyText,
        createdAt: new Date().toISOString().split('T')[0],
        isInternal: false,
      }],
      status: selectedTicket.status === 'open' ? 'in_progress' as const : selectedTicket.status,
      updatedAt: new Date().toISOString().split('T')[0],
    };
    updateTicket(updatedTicket);
    setSelectedTicket(updatedTicket);
    setReplyText('');
  };

  const handleStatusChange = (ticketId: string, newStatus: Ticket['status']) => {
    if (!selectedTicket) return;
    const updatedTicket = { ...selectedTicket, status: newStatus, updatedAt: new Date().toISOString().split('T')[0] };
    updateTicket(updatedTicket);
    setSelectedTicket(updatedTicket);
  };

  const handleCreateTicket = (e: React.FormEvent) => {
    e.preventDefault();
    const ticketNumber = generateTicketNumber();
    const ticket: Ticket = {
      id: Date.now().toString(),
      ticketNumber,
      subject: newTicket.subject,
      description: newTicket.description,
      category: newTicket.category,
      priority: newTicket.priority,
      status: 'open',
      createdBy: currentUser.name,
      assignedTo: newTicket.assignedTo || currentUser.id,
      createdAt: new Date().toISOString().split('T')[0],
      updatedAt: new Date().toISOString().split('T')[0],
      messages: [{
        id: '1',
        author: currentUser.name,
        content: newTicket.description,
        createdAt: new Date().toISOString().split('T')[0],
        isInternal: false,
      }],
    };
    addTicket(ticket);
    setShowCreateTicket(false);
    setNewTicket({ subject: '', description: '', category: '', priority: 'medium', assignedTo: '' });
  };

  const stats = {
    total: myTickets.length,
    open: myTickets.filter(t => t.status === 'open').length,
    inProgress: myTickets.filter(t => t.status === 'in_progress').length,
    resolved: myTickets.filter(t => t.status === 'resolved').length,
  };

  const supportUsers = users.filter(u => u.role === 'support');

  return (
    <div className="min-h-screen bg-gray-50">
      {/* Header */}
      <header className="bg-white shadow-sm border-b">
        <div className="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between">
          <div className="flex items-center">
            <LayoutDashboard className="w-6 h-6 text-blue-600 mr-2" />
            <h1 className="text-xl font-bold text-gray-800">Mesa de Ayuda</h1>
            <span className="ml-3 text-sm bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full">{currentUser.role === 'admin' ? 'Administrador' : 'Soporte'}</span>
          </div>
          <div className="flex items-center gap-4">
            <span className="text-sm text-gray-600">Hola, <strong>{currentUser.name}</strong></span>
            {currentUser.role === 'admin' && (
              <button onClick={() => navigate('/admin')} className="text-sm bg-purple-100 text-purple-700 px-3 py-1.5 rounded-lg hover:bg-purple-200 transition-colors">
                Panel Admin
              </button>
            )}
            <button onClick={handleLogout} className="flex items-center text-sm text-gray-500 hover:text-red-600 transition-colors">
              <LogOut className="w-4 h-4 mr-1" /> Salir
            </button>
          </div>
        </div>
      </header>

      <div className="max-w-7xl mx-auto px-4 py-6">
        {/* Stats */}
        <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
          <div className="bg-white rounded-xl p-4 shadow-sm border">
            <p className="text-sm text-gray-500">Total</p>
            <p className="text-2xl font-bold text-gray-800">{stats.total}</p>
          </div>
          <div className="bg-white rounded-xl p-4 shadow-sm border">
            <p className="text-sm text-blue-600">Abiertos</p>
            <p className="text-2xl font-bold text-blue-700">{stats.open}</p>
          </div>
          <div className="bg-white rounded-xl p-4 shadow-sm border">
            <p className="text-sm text-yellow-600">En Progreso</p>
            <p className="text-2xl font-bold text-yellow-700">{stats.inProgress}</p>
          </div>
          <div className="bg-white rounded-xl p-4 shadow-sm border">
            <p className="text-sm text-green-600">Resueltos</p>
            <p className="text-2xl font-bold text-green-700">{stats.resolved}</p>
          </div>
        </div>

        {/* Actions Bar */}
        <div className="flex items-center justify-between mb-4">
          <div className="flex items-center gap-2">
            <Filter className="w-4 h-4 text-gray-500" />
            <select
              value={filterStatus}
              onChange={(e) => setFilterStatus(e.target.value)}
              className="text-sm border border-gray-300 rounded-lg px-3 py-1.5 focus:outline-none focus:ring-2 focus:ring-blue-500"
            >
              <option value="all">Todos</option>
              <option value="open">Abiertos</option>
              <option value="in_progress">En Progreso</option>
              <option value="resolved">Resueltos</option>
              <option value="closed">Cerrados</option>
            </select>
          </div>
          <button
            onClick={() => setShowCreateTicket(true)}
            className="flex items-center bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors"
          >
            <Plus className="w-4 h-4 mr-1" /> Nuevo Ticket
          </button>
        </div>

        <div className="grid lg:grid-cols-3 gap-6">
          {/* Ticket List */}
          <div className="lg:col-span-1 space-y-3 max-h-[70vh] overflow-y-auto">
            {filteredTickets.length === 0 ? (
              <div className="bg-white rounded-xl p-6 text-center shadow-sm">
                <p className="text-gray-500">No hay tickets para mostrar</p>
              </div>
            ) : (
              filteredTickets.map(ticket => (
                <div
                  key={ticket.id}
                  onClick={() => setSelectedTicket(ticket)}
                  className={`bg-white rounded-xl p-4 shadow-sm cursor-pointer transition-all hover:shadow-md border-l-4 ${
                    selectedTicket?.id === ticket.id ? 'border-l-blue-600 ring-1 ring-blue-200' : 'border-l-transparent'
                  }`}
                >
                  <div className="flex items-center justify-between mb-1">
                    <span className="text-xs font-mono text-gray-500">{ticket.ticketNumber}</span>
                    <span className={`text-xs px-2 py-0.5 rounded-full ${statusConfig[ticket.status].bg} ${statusConfig[ticket.status].color}`}>
                      {statusConfig[ticket.status].label}
                    </span>
                  </div>
                  <h4 className="font-medium text-gray-800 text-sm">{ticket.subject}</h4>
                  <div className="flex items-center justify-between mt-2">
                    <span className="text-xs text-gray-500">{ticket.category}</span>
                    <div className="flex items-center">
                      <span className={`w-2 h-2 rounded-full ${priorityConfig[ticket.priority].dot} mr-1`}></span>
                      <span className={`text-xs ${priorityConfig[ticket.priority].color}`}>{priorityConfig[ticket.priority].label}</span>
                    </div>
                  </div>
                </div>
              ))
            )}
          </div>

          {/* Ticket Detail */}
          <div className="lg:col-span-2">
            {selectedTicket ? (
              <div className="bg-white rounded-xl shadow-sm p-6">
                <div className="flex items-center justify-between mb-4">
                  <div>
                    <h3 className="text-lg font-bold text-gray-800">{selectedTicket.subject}</h3>
                    <p className="text-sm text-gray-500">{selectedTicket.ticketNumber} • {selectedTicket.category}</p>
                  </div>
                  <div className="flex items-center gap-2">
                    <select
                      value={selectedTicket.status}
                      onChange={(e) => handleStatusChange(selectedTicket.id, e.target.value as Ticket['status'])}
                      className="text-sm border border-gray-300 rounded-lg px-3 py-1.5 focus:outline-none focus:ring-2 focus:ring-blue-500"
                    >
                      <option value="open">Abierto</option>
                      <option value="in_progress">En Progreso</option>
                      <option value="resolved">Resuelto</option>
                      <option value="closed">Cerrado</option>
                    </select>
                  </div>
                </div>

                <div className="bg-gray-50 rounded-lg p-3 mb-4">
                  <p className="text-sm text-gray-700">{selectedTicket.description}</p>
                  <p className="text-xs text-gray-500 mt-2">Creado por: {selectedTicket.createdBy} • {selectedTicket.createdAt}</p>
                </div>

                {/* Messages */}
                <div className="border-t pt-4 mb-4">
                  <h4 className="font-semibold text-gray-700 mb-3 flex items-center">
                    <MessageCircle className="w-4 h-4 mr-1" /> Conversación
                  </h4>
                  <div className="space-y-3 max-h-60 overflow-y-auto">
                    {selectedTicket.messages.filter(m => !m.isInternal).map(msg => (
                      <div key={msg.id} className={`rounded-lg p-3 ${msg.author === currentUser.name ? 'bg-blue-50 ml-4' : 'bg-gray-50 mr-4'}`}>
                        <div className="flex items-center justify-between mb-1">
                          <span className="text-sm font-medium text-gray-800">{msg.author}</span>
                          <span className="text-xs text-gray-500">{msg.createdAt}</span>
                        </div>
                        <p className="text-sm text-gray-600">{msg.content}</p>
                      </div>
                    ))}
                  </div>
                </div>

                {/* Reply */}
                <div className="border-t pt-4">
                  <textarea
                    value={replyText}
                    onChange={(e) => setReplyText(e.target.value)}
                    rows={3}
                    className="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 resize-none text-sm"
                    placeholder="Escribe tu respuesta..."
                  />
                  <button
                    onClick={() => handleReply(selectedTicket.id)}
                    disabled={!replyText.trim()}
                    className="mt-2 bg-blue-600 hover:bg-blue-700 disabled:bg-gray-300 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors"
                  >
                    Enviar Respuesta
                  </button>
                </div>
              </div>
            ) : (
              <div className="bg-white rounded-xl shadow-sm p-12 text-center">
                <Clock className="w-12 h-12 text-gray-300 mx-auto mb-3" />
                <p className="text-gray-500">Selecciona un ticket para ver los detalles</p>
              </div>
            )}
          </div>
        </div>
      </div>

      {/* Create Ticket Modal */}
      {showCreateTicket && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 px-4">
          <div className="bg-white rounded-2xl p-6 w-full max-w-lg max-h-[90vh] overflow-y-auto">
            <h3 className="text-xl font-bold text-gray-800 mb-4">Crear Nuevo Ticket</h3>
            <form onSubmit={handleCreateTicket} className="space-y-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Asunto</label>
                <input
                  type="text"
                  value={newTicket.subject}
                  onChange={(e) => setNewTicket({ ...newTicket, subject: e.target.value })}
                  className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                  required
                />
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">Categoría</label>
                  <select
                    value={newTicket.category}
                    onChange={(e) => setNewTicket({ ...newTicket, category: e.target.value })}
                    className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                    required
                  >
                    <option value="">Seleccionar</option>
                    {categories.map(cat => (
                      <option key={cat.id} value={cat.name}>{cat.name}</option>
                    ))}
                  </select>
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">Prioridad</label>
                  <select
                    value={newTicket.priority}
                    onChange={(e) => setNewTicket({ ...newTicket, priority: e.target.value as any })}
                    className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                  >
                    <option value="low">Baja</option>
                    <option value="medium">Media</option>
                    <option value="high">Alta</option>
                    <option value="critical">Crítica</option>
                  </select>
                </div>
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Asignar a</label>
                <select
                  value={newTicket.assignedTo}
                  onChange={(e) => setNewTicket({ ...newTicket, assignedTo: e.target.value })}
                  className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                >
                  <option value="">Sin asignar</option>
                  {supportUsers.map(u => (
                    <option key={u.id} value={u.id}>{u.name}</option>
                  ))}
                </select>
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Descripción</label>
                <textarea
                  value={newTicket.description}
                  onChange={(e) => setNewTicket({ ...newTicket, description: e.target.value })}
                  rows={4}
                  className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 resize-none"
                  required
                />
              </div>
              <div className="flex gap-3 pt-2">
                <button
                  type="button"
                  onClick={() => setShowCreateTicket(false)}
                  className="flex-1 bg-gray-100 hover:bg-gray-200 text-gray-700 font-medium py-2 rounded-lg transition-colors"
                >
                  Cancelar
                </button>
                <button
                  type="submit"
                  className="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 rounded-lg transition-colors"
                >
                  Crear Ticket
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
