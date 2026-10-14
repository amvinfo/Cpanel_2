import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { ArrowLeft, Users, Shield, BookOpen, Settings, Plus, Edit, Trash2, X, Save } from 'lucide-react';
import { useAuth } from '../contexts/AuthContext';
import { useData, User, Profile, KBArticle } from '../contexts/DataContext';

type Tab = 'users' | 'profiles' | 'kb-external' | 'kb-internal';

export default function Admin() {
  const { currentUser } = useAuth();
  const { users, profiles, kbArticles, categories, addUser, updateUser, deleteUser, addProfile, updateProfile, deleteProfile, addKBArticle, updateKBArticle, deleteKBArticle } = useData();
  const navigate = useNavigate();
  const [activeTab, setActiveTab] = useState<Tab>('users');
  const [showUserModal, setShowUserModal] = useState(false);
  const [showProfileModal, setShowProfileModal] = useState(false);
  const [showKBModal, setShowKBModal] = useState(false);
  const [editingUser, setEditingUser] = useState<User | null>(null);
  const [editingProfile, setEditingProfile] = useState<Profile | null>(null);
  const [editingKB, setEditingKB] = useState<KBArticle | null>(null);

  if (!currentUser || currentUser.role !== 'admin') {
    navigate('/login');
    return null;
  }

  const tabs: { id: Tab; label: string; icon: React.ReactNode }[] = [
    { id: 'users', label: 'Usuarios', icon: <Users className="w-4 h-4" /> },
    { id: 'profiles', label: 'Perfiles', icon: <Shield className="w-4 h-4" /> },
    { id: 'kb-external', label: 'KB Externa', icon: <BookOpen className="w-4 h-4" /> },
    { id: 'kb-internal', label: 'KB Interna', icon: <Settings className="w-4 h-4" /> },
  ];

  return (
    <div className="min-h-screen bg-gray-50">
      {/* Header */}
      <header className="bg-white shadow-sm border-b">
        <div className="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between">
          <div className="flex items-center">
            <button onClick={() => navigate('/dashboard')} className="mr-3 text-gray-500 hover:text-gray-700">
              <ArrowLeft className="w-5 h-5" />
            </button>
            <Settings className="w-6 h-6 text-purple-600 mr-2" />
            <h1 className="text-xl font-bold text-gray-800">Panel de Administración</h1>
          </div>
          <span className="text-sm text-gray-600">Administrador: <strong>{currentUser.name}</strong></span>
        </div>
      </header>

      <div className="max-w-7xl mx-auto px-4 py-6">
        {/* Tabs */}
        <div className="flex gap-2 mb-6 bg-white rounded-xl p-2 shadow-sm">
          {tabs.map(tab => (
            <button
              key={tab.id}
              onClick={() => setActiveTab(tab.id)}
              className={`flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
                activeTab === tab.id ? 'bg-purple-100 text-purple-700' : 'text-gray-600 hover:bg-gray-100'
              }`}
            >
              {tab.icon} {tab.label}
            </button>
          ))}
        </div>

        {/* Users Tab */}
        {activeTab === 'users' && (
          <div className="bg-white rounded-xl shadow-sm p-6">
            <div className="flex items-center justify-between mb-4">
              <h2 className="text-lg font-bold text-gray-800">Gestión de Usuarios</h2>
              <button
                onClick={() => { setEditingUser(null); setShowUserModal(true); }}
                className="flex items-center bg-purple-600 hover:bg-purple-700 text-white text-sm font-medium px-4 py-2 rounded-lg"
              >
                <Plus className="w-4 h-4 mr-1" /> Nuevo Usuario
              </button>
            </div>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="text-left px-4 py-3 font-medium text-gray-600">Usuario</th>
                    <th className="text-left px-4 py-3 font-medium text-gray-600">Nombre</th>
                    <th className="text-left px-4 py-3 font-medium text-gray-600">Email</th>
                    <th className="text-left px-4 py-3 font-medium text-gray-600">Rol</th>
                    <th className="text-left px-4 py-3 font-medium text-gray-600">Perfil</th>
                    <th className="text-left px-4 py-3 font-medium text-gray-600">Estado</th>
                    <th className="text-left px-4 py-3 font-medium text-gray-600">Acciones</th>
                  </tr>
                </thead>
                <tbody>
                  {users.map(user => (
                    <tr key={user.id} className="border-t hover:bg-gray-50">
                      <td className="px-4 py-3 font-mono">{user.username}</td>
                      <td className="px-4 py-3">{user.name}</td>
                      <td className="px-4 py-3 text-gray-500">{user.email}</td>
                      <td className="px-4 py-3">
                        <span className={`text-xs px-2 py-1 rounded-full ${user.role === 'admin' ? 'bg-purple-100 text-purple-700' : user.role === 'support' ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-700'}`}>
                          {user.role}
                        </span>
                      </td>
                      <td className="px-4 py-3">{profiles.find(p => p.id === user.profile)?.name || '-'}</td>
                      <td className="px-4 py-3">
                        <span className={`text-xs px-2 py-1 rounded-full ${user.active ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'}`}>
                          {user.active ? 'Activo' : 'Inactivo'}
                        </span>
                      </td>
                      <td className="px-4 py-3">
                        <div className="flex gap-1">
                          <button onClick={() => { setEditingUser(user); setShowUserModal(true); }} className="p-1 text-blue-600 hover:bg-blue-50 rounded">
                            <Edit className="w-4 h-4" />
                          </button>
                          <button onClick={() => deleteUser(user.id)} className="p-1 text-red-600 hover:bg-red-50 rounded">
                            <Trash2 className="w-4 h-4" />
                          </button>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        )}

        {/* Profiles Tab */}
        {activeTab === 'profiles' && (
          <div className="bg-white rounded-xl shadow-sm p-6">
            <div className="flex items-center justify-between mb-4">
              <h2 className="text-lg font-bold text-gray-800">Gestión de Perfiles</h2>
              <button
                onClick={() => { setEditingProfile(null); setShowProfileModal(true); }}
                className="flex items-center bg-purple-600 hover:bg-purple-700 text-white text-sm font-medium px-4 py-2 rounded-lg"
              >
                <Plus className="w-4 h-4 mr-1" /> Nuevo Perfil
              </button>
            </div>
            <div className="grid md:grid-cols-2 lg:grid-cols-3 gap-4">
              {profiles.map(profile => (
                <div key={profile.id} className="border rounded-xl p-4 hover:shadow-md transition-shadow">
                  <div className="flex items-center justify-between mb-2">
                    <h3 className="font-semibold text-gray-800">{profile.name}</h3>
                    <div className="flex gap-1">
                      <button onClick={() => { setEditingProfile(profile); setShowProfileModal(true); }} className="p-1 text-blue-600 hover:bg-blue-50 rounded">
                        <Edit className="w-4 h-4" />
                      </button>
                      <button onClick={() => deleteProfile(profile.id)} className="p-1 text-red-600 hover:bg-red-50 rounded">
                        <Trash2 className="w-4 h-4" />
                      </button>
                    </div>
                  </div>
                  <p className="text-sm text-gray-500 mb-3">{profile.description}</p>
                  <div className="flex flex-wrap gap-1">
                    {profile.permissions.map(perm => (
                      <span key={perm} className="text-xs bg-gray-100 text-gray-600 px-2 py-0.5 rounded-full">{perm}</span>
                    ))}
                  </div>
                </div>
              ))}
            </div>
          </div>
        )}

        {/* KB External Tab */}
        {(activeTab === 'kb-external' || activeTab === 'kb-internal') && (
          <div className="bg-white rounded-xl shadow-sm p-6">
            <div className="flex items-center justify-between mb-4">
              <h2 className="text-lg font-bold text-gray-800">
                Base de Conocimiento {activeTab === 'kb-internal' ? 'Interna' : 'Externa'}
              </h2>
              <button
                onClick={() => { setEditingKB(null); setShowKBModal(true); }}
                className="flex items-center bg-purple-600 hover:bg-purple-700 text-white text-sm font-medium px-4 py-2 rounded-lg"
              >
                <Plus className="w-4 h-4 mr-1" /> Nuevo Artículo
              </button>
            </div>
            <div className="space-y-3">
              {kbArticles.filter(a => activeTab === 'kb-internal' ? a.isInternal : !a.isInternal).map(article => (
                <div key={article.id} className="border rounded-xl p-4 hover:shadow-md transition-shadow">
                  <div className="flex items-center justify-between">
                    <div>
                      <h3 className="font-semibold text-gray-800">{article.title}</h3>
                      <p className="text-sm text-gray-500 mt-1 line-clamp-2">{article.content}</p>
                      <div className="flex items-center gap-2 mt-2">
                        <span className="text-xs bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full">{article.category}</span>
                        <span className="text-xs text-gray-400">Por: {article.author} • {article.updatedAt}</span>
                      </div>
                    </div>
                    <div className="flex gap-1">
                      <button onClick={() => { setEditingKB(article); setShowKBModal(true); }} className="p-2 text-blue-600 hover:bg-blue-50 rounded">
                        <Edit className="w-4 h-4" />
                      </button>
                      <button onClick={() => deleteKBArticle(article.id)} className="p-2 text-red-600 hover:bg-red-50 rounded">
                        <Trash2 className="w-4 h-4" />
                      </button>
                    </div>
                  </div>
                </div>
              ))}
            </div>
          </div>
        )}
      </div>

      {/* User Modal */}
      {showUserModal && (
        <UserModal
          user={editingUser}
          profiles={profiles}
          onSave={(user) => {
            if (editingUser) updateUser(user);
            else addUser(user);
            setShowUserModal(false);
          }}
          onClose={() => setShowUserModal(false)}
        />
      )}

      {/* Profile Modal */}
      {showProfileModal && (
        <ProfileModal
          profile={editingProfile}
          onSave={(profile) => {
            if (editingProfile) updateProfile(profile);
            else addProfile(profile);
            setShowProfileModal(false);
          }}
          onClose={() => setShowProfileModal(false)}
        />
      )}

      {/* KB Modal */}
      {showKBModal && (
        <KBModal
          article={editingKB}
          isInternal={activeTab === 'kb-internal'}
          categories={categories}
          author={currentUser.name}
          onSave={(article) => {
            if (editingKB) updateKBArticle(article);
            else addKBArticle(article);
            setShowKBModal(false);
          }}
          onClose={() => setShowKBModal(false)}
        />
      )}
    </div>
  );
}

// User Modal Component
function UserModal({ user, profiles, onSave, onClose }: { user: User | null; profiles: Profile[]; onSave: (user: User) => void; onClose: () => void }) {
  const [form, setForm] = useState<User>(user || {
    id: Date.now().toString(),
    username: '',
    password: '',
    name: '',
    email: '',
    role: 'support',
    profile: profiles[0]?.id || '',
    active: true,
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    onSave(form);
  };

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 px-4">
      <div className="bg-white rounded-2xl p-6 w-full max-w-md">
        <div className="flex items-center justify-between mb-4">
          <h3 className="text-lg font-bold text-gray-800">{user ? 'Editar' : 'Nuevo'} Usuario</h3>
          <button onClick={onClose} className="text-gray-400 hover:text-gray-600"><X className="w-5 h-5" /></button>
        </div>
        <form onSubmit={handleSubmit} className="space-y-3">
          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="block text-xs font-medium text-gray-700 mb-1">Usuario</label>
              <input type="text" value={form.username} onChange={(e) => setForm({ ...form, username: e.target.value })} className="w-full px-3 py-2 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-purple-500" required />
            </div>
            <div>
              <label className="block text-xs font-medium text-gray-700 mb-1">Contraseña</label>
              <input type="text" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} className="w-full px-3 py-2 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-purple-500" required />
            </div>
          </div>
          <div>
            <label className="block text-xs font-medium text-gray-700 mb-1">Nombre completo</label>
            <input type="text" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} className="w-full px-3 py-2 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-purple-500" required />
          </div>
          <div>
            <label className="block text-xs font-medium text-gray-700 mb-1">Email</label>
            <input type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} className="w-full px-3 py-2 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-purple-500" required />
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="block text-xs font-medium text-gray-700 mb-1">Rol</label>
              <select value={form.role} onChange={(e) => setForm({ ...form, role: e.target.value as any })} className="w-full px-3 py-2 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-purple-500">
                <option value="admin">Administrador</option>
                <option value="support">Soporte</option>
                <option value="user">Usuario</option>
              </select>
            </div>
            <div>
              <label className="block text-xs font-medium text-gray-700 mb-1">Perfil</label>
              <select value={form.profile} onChange={(e) => setForm({ ...form, profile: e.target.value })} className="w-full px-3 py-2 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-purple-500">
                {profiles.map(p => <option key={p.id} value={p.id}>{p.name}</option>)}
              </select>
            </div>
          </div>
          <div className="flex items-center gap-2">
            <input type="checkbox" checked={form.active} onChange={(e) => setForm({ ...form, active: e.target.checked })} className="rounded" />
            <label className="text-sm text-gray-700">Usuario activo</label>
          </div>
          <div className="flex gap-3 pt-2">
            <button type="button" onClick={onClose} className="flex-1 bg-gray-100 hover:bg-gray-200 text-gray-700 font-medium py-2 rounded-lg text-sm">Cancelar</button>
            <button type="submit" className="flex-1 bg-purple-600 hover:bg-purple-700 text-white font-medium py-2 rounded-lg text-sm flex items-center justify-center"><Save className="w-4 h-4 mr-1" /> Guardar</button>
          </div>
        </form>
      </div>
    </div>
  );
}

// Profile Modal Component
function ProfileModal({ profile, onSave, onClose }: { profile: Profile | null; onSave: (profile: Profile) => void; onClose: () => void }) {
  const [form, setForm] = useState<Profile>(profile || {
    id: Date.now().toString(),
    name: '',
    description: '',
    permissions: [],
  });
  const [newPerm, setNewPerm] = useState('');

  const availablePermissions = ['view_tickets', 'respond_tickets', 'view_kb', 'manage_kb', 'assign_tickets', 'manage_users', 'manage_profiles', 'all'];

  const addPermission = () => {
    if (newPerm && !form.permissions.includes(newPerm)) {
      setForm({ ...form, permissions: [...form.permissions, newPerm] });
      setNewPerm('');
    }
  };

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    onSave(form);
  };

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 px-4">
      <div className="bg-white rounded-2xl p-6 w-full max-w-md">
        <div className="flex items-center justify-between mb-4">
          <h3 className="text-lg font-bold text-gray-800">{profile ? 'Editar' : 'Nuevo'} Perfil</h3>
          <button onClick={onClose} className="text-gray-400 hover:text-gray-600"><X className="w-5 h-5" /></button>
        </div>
        <form onSubmit={handleSubmit} className="space-y-3">
          <div>
            <label className="block text-xs font-medium text-gray-700 mb-1">Nombre del perfil</label>
            <input type="text" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} className="w-full px-3 py-2 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-purple-500" required />
          </div>
          <div>
            <label className="block text-xs font-medium text-gray-700 mb-1">Descripción</label>
            <textarea value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} rows={2} className="w-full px-3 py-2 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-purple-500 resize-none" />
          </div>
          <div>
            <label className="block text-xs font-medium text-gray-700 mb-1">Permisos</label>
            <div className="flex gap-2 mb-2">
              <select value={newPerm} onChange={(e) => setNewPerm(e.target.value)} className="flex-1 px-3 py-2 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-purple-500">
                <option value="">Seleccionar permiso</option>
                {availablePermissions.filter(p => !form.permissions.includes(p)).map(p => (
                  <option key={p} value={p}>{p}</option>
                ))}
              </select>
              <button type="button" onClick={addPermission} className="bg-purple-100 text-purple-700 px-3 py-2 rounded-lg text-sm hover:bg-purple-200">
                <Plus className="w-4 h-4" />
              </button>
            </div>
            <div className="flex flex-wrap gap-1">
              {form.permissions.map(perm => (
                <span key={perm} className="text-xs bg-purple-100 text-purple-700 px-2 py-1 rounded-full flex items-center gap-1">
                  {perm}
                  <button type="button" onClick={() => setForm({ ...form, permissions: form.permissions.filter(p => p !== perm) })} className="hover:text-red-600">
                    <X className="w-3 h-3" />
                  </button>
                </span>
              ))}
            </div>
          </div>
          <div className="flex gap-3 pt-2">
            <button type="button" onClick={onClose} className="flex-1 bg-gray-100 hover:bg-gray-200 text-gray-700 font-medium py-2 rounded-lg text-sm">Cancelar</button>
            <button type="submit" className="flex-1 bg-purple-600 hover:bg-purple-700 text-white font-medium py-2 rounded-lg text-sm flex items-center justify-center"><Save className="w-4 h-4 mr-1" /> Guardar</button>
          </div>
        </form>
      </div>
    </div>
  );
}

// KB Modal Component
function KBModal({ article, isInternal, categories, author, onSave, onClose }: { article: KBArticle | null; isInternal: boolean; categories: { id: string; name: string }[]; author: string; onSave: (article: KBArticle) => void; onClose: () => void }) {
  const [form, setForm] = useState<KBArticle>(article || {
    id: Date.now().toString(),
    title: '',
    content: '',
    category: categories[0]?.name || '',
    tags: [],
    isInternal,
    createdAt: new Date().toISOString().split('T')[0],
    updatedAt: new Date().toISOString().split('T')[0],
    author,
  });
  const [tagInput, setTagInput] = useState('');

  const addTag = () => {
    if (tagInput.trim() && !form.tags.includes(tagInput.trim())) {
      setForm({ ...form, tags: [...form.tags, tagInput.trim()] });
      setTagInput('');
    }
  };

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    onSave({ ...form, updatedAt: new Date().toISOString().split('T')[0] });
  };

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 px-4">
      <div className="bg-white rounded-2xl p-6 w-full max-w-lg max-h-[90vh] overflow-y-auto">
        <div className="flex items-center justify-between mb-4">
          <h3 className="text-lg font-bold text-gray-800">{article ? 'Editar' : 'Nuevo'} Artículo KB</h3>
          <button onClick={onClose} className="text-gray-400 hover:text-gray-600"><X className="w-5 h-5" /></button>
        </div>
        <form onSubmit={handleSubmit} className="space-y-3">
          <div>
            <label className="block text-xs font-medium text-gray-700 mb-1">Título</label>
            <input type="text" value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} className="w-full px-3 py-2 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-purple-500" required />
          </div>
          <div>
            <label className="block text-xs font-medium text-gray-700 mb-1">Categoría</label>
            <select value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })} className="w-full px-3 py-2 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-purple-500">
              {categories.map(cat => <option key={cat.id} value={cat.name}>{cat.name}</option>)}
            </select>
          </div>
          <div>
            <label className="block text-xs font-medium text-gray-700 mb-1">Contenido</label>
            <textarea value={form.content} onChange={(e) => setForm({ ...form, content: e.target.value })} rows={6} className="w-full px-3 py-2 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-purple-500 resize-none" required />
          </div>
          <div>
            <label className="block text-xs font-medium text-gray-700 mb-1">Etiquetas</label>
            <div className="flex gap-2 mb-2">
              <input type="text" value={tagInput} onChange={(e) => setTagInput(e.target.value)} placeholder="Agregar etiqueta" className="flex-1 px-3 py-2 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-purple-500" onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); addTag(); } }} />
              <button type="button" onClick={addTag} className="bg-purple-100 text-purple-700 px-3 py-2 rounded-lg text-sm hover:bg-purple-200">
                <Plus className="w-4 h-4" />
              </button>
            </div>
            <div className="flex flex-wrap gap-1">
              {form.tags.map(tag => (
                <span key={tag} className="text-xs bg-gray-100 text-gray-600 px-2 py-1 rounded-full flex items-center gap-1">
                  #{tag}
                  <button type="button" onClick={() => setForm({ ...form, tags: form.tags.filter(t => t !== tag) })} className="hover:text-red-600">
                    <X className="w-3 h-3" />
                  </button>
                </span>
              ))}
            </div>
          </div>
          <div className="flex items-center gap-2">
            <input type="checkbox" checked={form.isInternal} onChange={(e) => setForm({ ...form, isInternal: e.target.checked })} className="rounded" />
            <label className="text-sm text-gray-700">Artículo interno (solo visible para soporte)</label>
          </div>
          <div className="flex gap-3 pt-2">
            <button type="button" onClick={onClose} className="flex-1 bg-gray-100 hover:bg-gray-200 text-gray-700 font-medium py-2 rounded-lg text-sm">Cancelar</button>
            <button type="submit" className="flex-1 bg-purple-600 hover:bg-purple-700 text-white font-medium py-2 rounded-lg text-sm flex items-center justify-center"><Save className="w-4 h-4 mr-1" /> Guardar</button>
          </div>
        </form>
      </div>
    </div>
  );
}
