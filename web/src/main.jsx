import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { createBrowserRouter, Outlet, RouterProvider } from 'react-router'
import './index.css'
import { AuthProvider } from './lib/auth'
import PublicLayout from './layouts/PublicLayout'
import AppLayout, { RequireAuth } from './layouts/AppLayout'
import Landing from './pages/public/Landing'
import NotFound from './pages/public/NotFound'
import SignIn from './pages/public/SignIn'
import SignUp from './pages/public/SignUp'
import Terms from './pages/public/Terms'
import Feed from './pages/app/Feed'
import Projects from './pages/app/Projects'
import NewProject from './pages/app/NewProject'
import ProjectSettings from './pages/app/ProjectSettings'

const router = createBrowserRouter([
  {
    element: (
      <AuthProvider>
        <Outlet />
      </AuthProvider>
    ),
    children: [
      {
        element: <PublicLayout />,
        children: [
          { path: '/', element: <Landing /> },
          { path: '/signin', element: <SignIn /> },
          { path: '/signup', element: <SignUp /> },
          { path: '/terms', element: <Terms /> },
          { path: '*', element: <NotFound /> },
        ],
      },
      {
        path: '/app',
        element: (
          <RequireAuth>
            <AppLayout />
          </RequireAuth>
        ),
        children: [
          { index: true, element: <Feed /> },
          { path: 'projects', element: <Projects /> },
          { path: 'projects/new', element: <NewProject /> },
          { path: 'projects/:slug', element: <ProjectSettings /> },
          { path: '*', element: <NotFound /> },
        ],
      },
    ],
  },
])

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <RouterProvider router={router} />
  </StrictMode>,
)
