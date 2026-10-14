import React, { createContext, useContext, useState, useEffect, ReactNode } from 'react';

export interface User {
  id: string;
  username: string;
  password: string;
  name: string;
  email: string;
  role: 'admin' | 'support' | 'user';
  profile: string;
  active: boolean;
}

export interface Profile {
  id: string;
  name: string;
  permissions: string[];
  description: string;
}

export interface Ticket {
  id: string;
  ticketNumber: string;
  subject: string;
  description: string;
  category: string;
  priority: 'low' | 'medium' | 'high' | 'critical';
  status: 'open' | 'in_progress' | 'resolved' | 'closed';
  createdBy: string;
  assignedTo: string;
  createdAt: string;
  updatedAt: string;
  messages: TicketMessage[];
}

export interface TicketMessage {
  id: string;
  author: string;
  content: string;
  createdAt: string;
  isInternal: boolean;
}

export interface KBArticle {
  id: string;
  title: string;
  content: string;
  category: string;
  tags: string[];
  isInternal: boolean;
  createdAt: string;
  updatedAt: string;
  author: string;
}

export interface Category {
  id: string;
  name: string;
  description: string;
  icon: string;
}

interface DataContextType {
  users: User[];
  profiles: Profile[];
  tickets: Ticket[];
  kbArticles: KBArticle[];
  categories: Category[];
  addUser: (user: User) => void;
  updateUser: (user: User) => void;
  deleteUser: (id: string) => void;
  addProfile: (profile: Profile) => void;
  updateProfile: (profile: Profile) => void;
  deleteProfile: (id: string) => void;
  addTicket: (ticket: Ticket) => void;
  updateTicket: (ticket: Ticket) => void;
  addKBArticle: (article: KBArticle) => void;
  updateKBArticle: (article: KBArticle) => void;
  deleteKBArticle: (id: string) => void;
  generateTicketNumber: () => string;
}

const DataContext = createContext<DataContextType | undefined>(undefined);

const defaultCategories: Category[] = [
  { id: '1', name: 'Hardware', description: 'Problemas con equipos y dispositivos', icon: '🖥️' },
  { id: '2', name: 'Software', description: 'Problemas con aplicaciones y programas', icon: '💿' },
  { id: '3', name: 'Red', description: 'Conectividad y problemas de red', icon: '🌐' },
  { id: '4', name: 'Correo', description: 'Problemas con email y comunicación', icon: '📧' },
  { id: '5', name: 'Accesos', description: 'Problemas de acceso y permisos', icon: '🔑' },
  { id: '6', name: 'Impresoras', description: 'Problemas con impresión', icon: '🖨️' },
];

const defaultProfiles: Profile[] = [
  { id: '1', name: 'Administrador', description: 'Acceso total al sistema', permissions: ['all'] },
  { id: '2', name: 'Soporte Nivel 1', description: 'Atención básica de tickets', permissions: ['view_tickets', 'respond_tickets', 'view_kb'] },
  { id: '3', name: 'Soporte Nivel 2', description: 'Atención avanzada de tickets', permissions: ['view_tickets', 'respond_tickets', 'view_kb', 'manage_kb', 'assign_tickets'] },
];

const defaultUsers: User[] = [
  { id: '1', username: 'admin', password: 'admin123', name: 'Administrador del Sistema', email: 'admin@empresa.com', role: 'admin', profile: '1', active: true },
  { id: '2', username: 'soporte1', password: 'soporte123', name: 'Juan Pérez', email: 'juan@empresa.com', role: 'support', profile: '2', active: true },
  { id: '3', username: 'soporte2', password: 'soporte123', name: 'María García', email: 'maria@empresa.com', role: 'support', profile: '3', active: true },
];

const defaultKB: KBArticle[] = [
  { id: '1', title: 'Cómo resetear tu contraseña', content: 'Para resetear tu contraseña, sigue estos pasos:\n1. Ve a la página de inicio de sesión\n2. Haz clic en "¿Olvidaste tu contraseña?"\n3. Ingresa tu correo electrónico\n4. Revisa tu bandeja de entrada\n5. Sigue el enlace del correo para crear una nueva contraseña', category: 'Accesos', tags: ['contraseña', 'acceso', 'login'], isInternal: false, createdAt: '2024-01-15', updatedAt: '2024-01-15', author: 'admin' },
  { id: '2', title: 'Configurar impresora de red', content: 'Para configurar una impresora de red:\n1. Ve a Configuración > Dispositivos > Impresoras\n2. Haz clic en "Agregar impresora"\n3. Selecciona la impresora de la lista de red\n4. Instala los drivers si es necesario\n5. Imprime una página de prueba', category: 'Impresoras', tags: ['impresora', 'red', 'configuración'], isInternal: false, createdAt: '2024-01-20', updatedAt: '2024-01-20', author: 'admin' },
  { id: '3', title: 'Solución a lentitud del equipo', content: 'Si tu equipo está lento:\n1. Reinicia el equipo\n2. Cierra programas innecesarios\n3. Ejecuta el antivirus\n4. Limpia archivos temporales\n5. Si persiste, contacta a soporte', category: 'Hardware', tags: ['lentitud', 'rendimiento', 'optimización'], isInternal: false, createdAt: '2024-02-01', updatedAt: '2024-02-01', author: 'admin' },
  { id: '4', title: 'Procedimiento de reemplazo de equipos', content: 'Procedimiento interno para reemplazo de equipos:\n1. Verificar garantía del equipo\n2. Llenar formulario de baja de activo\n3. Coordinar con inventario\n4. Asignar equipo de reemplazo\n5. Migrar datos del usuario', category: 'Hardware', tags: ['reemplazo', 'activo', 'inventario'], isInternal: true, createdAt: '2024-02-10', updatedAt: '2024-02-10', author: 'admin' },
];

const defaultTickets: Ticket[] = [
  { id: '1', ticketNumber: 'TK-001', subject: 'No puedo acceder al sistema', description: 'Desde ayer no puedo ingresar al sistema corporativo, me aparece un error de credenciales.', category: 'Accesos', priority: 'high', status: 'open', createdBy: 'usuario1', assignedTo: '2', createdAt: '2024-03-01', updatedAt: '2024-03-01', messages: [{ id: '1', author: 'usuario1', content: 'Necesito ayuda urgente, no puedo trabajar sin acceso.', createdAt: '2024-03-01', isInternal: false }] },
  { id: '2', ticketNumber: 'TK-002', subject: 'Impresora no imprime', description: 'La impresora del piso 3 no responde a los comandos de impresión.', category: 'Impresoras', priority: 'medium', status: 'in_progress', createdBy: 'usuario2', assignedTo: '3', createdAt: '2024-03-02', updatedAt: '2024-03-02', messages: [{ id: '1', author: 'usuario2', content: 'La impresora está encendida pero no imprime.', createdAt: '2024-03-02', isInternal: false }, { id: '2', author: 'María García', content: 'Estamos revisando la conexión de red de la impresora.', createdAt: '2024-03-02', isInternal: false }] },
];

export function DataProvider({ children }: { children: ReactNode }) {
  const [users, setUsers] = useState<User[]>(() => {
    const saved = localStorage.getItem('helpdesk_users');
    return saved ? JSON.parse(saved) : defaultUsers;
  });

  const [profiles, setProfiles] = useState<Profile[]>(() => {
    const saved = localStorage.getItem('helpdesk_profiles');
    return saved ? JSON.parse(saved) : defaultProfiles;
  });

  const [tickets, setTickets] = useState<Ticket[]>(() => {
    const saved = localStorage.getItem('helpdesk_tickets');
    return saved ? JSON.parse(saved) : defaultTickets;
  });

  const [kbArticles, setKbArticles] = useState<KBArticle[]>(() => {
    const saved = localStorage.getItem('helpdesk_kb');
    return saved ? JSON.parse(saved) : defaultKB;
  });

  const [categories] = useState<Category[]>(defaultCategories);

  useEffect(() => { localStorage.setItem('helpdesk_users', JSON.stringify(users)); }, [users]);
  useEffect(() => { localStorage.setItem('helpdesk_profiles', JSON.stringify(profiles)); }, [profiles]);
  useEffect(() => { localStorage.setItem('helpdesk_tickets', JSON.stringify(tickets)); }, [tickets]);
  useEffect(() => { localStorage.setItem('helpdesk_kb', JSON.stringify(kbArticles)); }, [kbArticles]);

  const addUser = (user: User) => setUsers(prev => [...prev, user]);
  const updateUser = (user: User) => setUsers(prev => prev.map(u => u.id === user.id ? user : u));
  const deleteUser = (id: string) => setUsers(prev => prev.filter(u => u.id !== id));

  const addProfile = (profile: Profile) => setProfiles(prev => [...prev, profile]);
  const updateProfile = (profile: Profile) => setProfiles(prev => prev.map(p => p.id === profile.id ? profile : p));
  const deleteProfile = (id: string) => setProfiles(prev => prev.filter(p => p.id !== id));

  const addTicket = (ticket: Ticket) => setTickets(prev => [...prev, ticket]);
  const updateTicket = (ticket: Ticket) => setTickets(prev => prev.map(t => t.id === ticket.id ? ticket : t));

  const addKBArticle = (article: KBArticle) => setKbArticles(prev => [...prev, article]);
  const updateKBArticle = (article: KBArticle) => setKbArticles(prev => prev.map(a => a.id === article.id ? article : a));
  const deleteKBArticle = (id: string) => setKbArticles(prev => prev.filter(a => a.id !== id));

  const generateTicketNumber = () => {
    const num = tickets.length + 1;
    return `TK-${String(num).padStart(3, '0')}`;
  };

  return (
    <DataContext.Provider value={{
      users, profiles, tickets, kbArticles, categories,
      addUser, updateUser, deleteUser,
      addProfile, updateProfile, deleteProfile,
      addTicket, updateTicket,
      addKBArticle, updateKBArticle, deleteKBArticle,
      generateTicketNumber,
    }}>
      {children}
    </DataContext.Provider>
  );
}

export function useData() {
  const context = useContext(DataContext);
  if (!context) throw new Error('useData must be used within DataProvider');
  return context;
}
