import { lazy, StrictMode } from 'react'
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
import Privacy from './pages/public/Privacy'
import Cookies from './pages/public/Cookies'
import Faq from './pages/public/Faq'

// The dashboard pages (and the realtime client they use) load on demand, so public pages stay small. Public pages are
// bundled up front: loading them lazily made the footer jump when the text arrived.
// oxlint-disable react/only-export-components
const EventDetail = lazy(() => import('./pages/app/EventDetail'))
const Feed = lazy(() => import('./pages/app/Feed'))
const FeedIndex = lazy(() => import('./pages/app/FeedIndex'))
const Projects = lazy(() => import('./pages/app/Projects'))
const NewProject = lazy(() => import('./pages/app/NewProject'))
const ProjectSettings = lazy(() => import('./pages/app/ProjectSettings'))

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
          { path: '/privacy', element: <Privacy /> },
          { path: '/cookies', element: <Cookies /> },
          { path: '/faq', element: <Faq /> },
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
          { index: true, element: <FeedIndex /> },
          { path: 'projects', element: <Projects /> },
          { path: 'projects/new', element: <NewProject /> },
          { path: 'projects/:slug', element: <ProjectSettings /> },
          { path: 'projects/:slug/feed', element: <Feed /> },
          { path: 'projects/:slug/events/:id', element: <EventDetail /> },
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
