import { Navigate, Outlet, useLocation } from 'react-router-dom';
import { useAuth } from '../../contexts/AuthContext';
import { Spinner } from '../ui/Spinner';

export function RequireAuth() {
  const { user, isLoading } = useAuth();
  const location = useLocation();

  if (isLoading) {
    return <Spinner label="Checking your session…" />;
  }

  if (!user) {
    return <Navigate to="/login" state={{ from: location }} replace />;
  }

  return <Outlet />;
}

export function RequireAdmin() {
  const { user, isLoading } = useAuth();

  if (isLoading) {
    return <Spinner label="Checking your session…" />;
  }

  if (!user) {
    return <Navigate to="/login" replace />;
  }

  if (!user.is_admin) {
    return <Navigate to="/dashboard" replace />;
  }

  return <Outlet />;
}
