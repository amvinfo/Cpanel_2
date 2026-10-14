import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Search, Headphones, FileSearch, BookOpen } from 'lucide-react';
import { useData } from '../contexts/DataContext';

export default function Home() {
  const [searchQuery, setSearchQuery] = useState('');
  const [searchResults, setSearchResults] = useState<any[]>([]);
  const [showResults, setShowResults] = useState(false);
  const navigate = useNavigate();
  const { kbArticles, categories } = useData();

  const handleSearch = (e: React.FormEvent) => {
    e.preventDefault();
    if (!searchQuery.trim()) return;
    const results = kbArticles.filter(article =>
      !article.isInternal && (
        article.title.toLowerCase().includes(searchQuery.toLowerCase()) ||
        article.content.toLowerCase().includes(searchQuery.toLowerCase()) ||
        article.tags.some(tag => tag.toLowerCase().includes(searchQuery.toLowerCase()))
      )
    );
    setSearchResults(results);
    setShowResults(true);
  };

  const filteredCategories = categories;

  return (
    <div className="min-h-screen bg-gradient-to-br from-blue-50 via-white to-indigo-50">
      {/* Hero Section */}
      <div className="bg-gradient-to-r from-blue-600 to-indigo-700 text-white py-16 px-4">
        <div className="max-w-4xl mx-auto text-center">
          <div className="flex items-center justify-center mb-4">
            <Headphones className="w-12 h-12 mr-3" />
            <h1 className="text-4xl font-bold">Mesa de Ayuda</h1>
          </div>
          <p className="text-xl text-blue-100 mb-8">Centro de soporte técnico - Estamos aquí para ayudarte</p>
          
          {/* Search Bar */}
          <form onSubmit={handleSearch} className="max-w-2xl mx-auto">
            <div className="relative">
              <Search className="absolute left-4 top-1/2 transform -translate-y-1/2 text-gray-400 w-5 h-5" />
              <input
                type="text"
                placeholder="Buscar en la base de conocimiento..."
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                className="w-full pl-12 pr-4 py-4 rounded-xl text-gray-800 text-lg shadow-lg focus:outline-none focus:ring-4 focus:ring-blue-300"
              />
              <button
                type="submit"
                className="absolute right-2 top-1/2 transform -translate-y-1/2 bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-medium transition-colors"
              >
                Buscar
              </button>
            </div>
          </form>
        </div>
      </div>

      {/* Search Results */}
      {showResults && (
        <div className="max-w-4xl mx-auto px-4 py-8">
          <div className="bg-white rounded-xl shadow-lg p-6">
            <h3 className="text-xl font-bold text-gray-800 mb-4 flex items-center">
              <BookOpen className="w-5 h-5 mr-2 text-blue-600" />
              Resultados de búsqueda ({searchResults.length})
            </h3>
            {searchResults.length === 0 ? (
              <p className="text-gray-500">No se encontraron resultados para "{searchQuery}"</p>
            ) : (
              <div className="space-y-4">
                {searchResults.map(article => (
                  <div key={article.id} className="border border-gray-200 rounded-lg p-4 hover:shadow-md transition-shadow">
                    <h4 className="font-semibold text-gray-800">{article.title}</h4>
                    <p className="text-gray-600 text-sm mt-1 line-clamp-2">{article.content}</p>
                    <div className="flex items-center mt-2">
                      <span className="text-xs bg-blue-100 text-blue-700 px-2 py-1 rounded-full">{article.category}</span>
                      {article.tags.map((tag: string) => (
                        <span key={tag} className="text-xs bg-gray-100 text-gray-600 px-2 py-1 rounded-full ml-2">#{tag}</span>
                      ))}
                    </div>
                  </div>
                ))}
              </div>
            )}
            <button
              onClick={() => setShowResults(false)}
              className="mt-4 text-blue-600 hover:text-blue-800 font-medium"
            >
              ← Volver al inicio
            </button>
          </div>
        </div>
      )}

      {/* Main Content */}
      {!showResults && (
        <div className="max-w-6xl mx-auto px-4 py-12">
          {/* Categories */}
          <div className="mb-12">
            <h2 className="text-2xl font-bold text-gray-800 mb-6 text-center">Categorías de Soporte</h2>
            <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
              {filteredCategories.map(cat => (
                <div
                  key={cat.id}
                  className="bg-white rounded-xl shadow-md p-4 text-center hover:shadow-lg transition-shadow cursor-pointer hover:-translate-y-1 transform duration-200"
                >
                  <div className="text-3xl mb-2">{cat.icon}</div>
                  <h3 className="font-semibold text-gray-800 text-sm">{cat.name}</h3>
                  <p className="text-xs text-gray-500 mt-1">{cat.description}</p>
                </div>
              ))}
            </div>
          </div>

          {/* Action Cards */}
          <div className="grid md:grid-cols-2 gap-8 max-w-4xl mx-auto">
            {/* Solicitar Soporte */}
            <div
              onClick={() => navigate('/submit-ticket')}
              className="bg-white rounded-2xl shadow-lg p-8 hover:shadow-xl transition-all cursor-pointer hover:-translate-y-1 transform duration-200 border-t-4 border-green-500"
            >
              <div className="flex items-center mb-4">
                <div className="bg-green-100 p-3 rounded-xl mr-4">
                  <Headphones className="w-8 h-8 text-green-600" />
                </div>
                <div>
                  <h3 className="text-xl font-bold text-gray-800">Solicitar Soporte Técnico</h3>
                  <p className="text-sm text-gray-500">¿Necesitas ayuda?</p>
                </div>
              </div>
              <p className="text-gray-600">
                Crea un nuevo ticket de soporte para reportar un problema o solicitar asistencia técnica. Nuestro equipo te responderá lo antes posible.
              </p>
              <div className="mt-6">
                <span className="inline-flex items-center text-green-600 font-semibold">
                  Crear ticket →
                </span>
              </div>
            </div>

            {/* Consultar Estado */}
            <div
              onClick={() => navigate('/check-ticket')}
              className="bg-white rounded-2xl shadow-lg p-8 hover:shadow-xl transition-all cursor-pointer hover:-translate-y-1 transform duration-200 border-t-4 border-blue-500"
            >
              <div className="flex items-center mb-4">
                <div className="bg-blue-100 p-3 rounded-xl mr-4">
                  <FileSearch className="w-8 h-8 text-blue-600" />
                </div>
                <div>
                  <h3 className="text-xl font-bold text-gray-800">Consultar Estado de Ticket</h3>
                  <p className="text-sm text-gray-500">Revisa el progreso</p>
                </div>
              </div>
              <p className="text-gray-600">
                Revisa el progreso de tu solicitud ingresando el número de ticket asignado. Podrás ver el estado actual y los mensajes del equipo de soporte.
              </p>
              <div className="mt-6">
                <span className="inline-flex items-center text-blue-600 font-semibold">
                  Consultar ticket →
                </span>
              </div>
            </div>
          </div>

          {/* Recent KB Articles */}
          <div className="mt-12">
            <h2 className="text-2xl font-bold text-gray-800 mb-6 text-center">Artículos Recientes</h2>
            <div className="grid md:grid-cols-3 gap-4">
              {kbArticles.filter(a => !a.isInternal).slice(0, 3).map(article => (
                <div key={article.id} className="bg-white rounded-xl shadow-md p-5 hover:shadow-lg transition-shadow">
                  <span className="text-xs bg-blue-100 text-blue-700 px-2 py-1 rounded-full">{article.category}</span>
                  <h4 className="font-semibold text-gray-800 mt-3">{article.title}</h4>
                  <p className="text-sm text-gray-500 mt-2 line-clamp-3">{article.content}</p>
                </div>
              ))}
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
