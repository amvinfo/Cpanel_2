import React from 'react';
import { Link, useLocation } from 'react-router-dom';
import { Home, LogIn } from 'lucide-react';
import { useAuth } from '../contexts/AuthContext';

export default function Navbar() {
  const location = useLocation();
  const { isAuthenticated } = useAuth();

  // Don't show navbar on login, dashboard, or admin pages
  if (isAuthenticated || location.pathname === '/login' || location.pathname === '/dashboard' || location.pathname === '/admin') {
    return null;
  }

  return (
    <nav className="bg-white shadow-sm border-b sticky top-0 z-40">
      <div className="max-w-6xl mx-auto px-4 py-3 flex items-center justify-between">
        <Link to="/" className="flex items-center text-blue-700 font-bold text-lg">
          <span className="text-2xl mr-2">🎧</span> Mesa de Ayuda
        </Link>
        <div className="flex items-center gap-4">
          <Link to="/" className="text-sm text-gray-600 hover:text-blue-600 flex items-center gap-1">
            <Home className="w-4 h-4" /> Inicio
          </Link>
          <Link to="/login" className="flex items-center bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors">
            <LogIn className="w-4 h-4 mr-1" /> Acceder
          </Link>
        </div>
      </div>
    </nav>
  );
}
