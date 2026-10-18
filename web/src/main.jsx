import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { createBrowserRouter, RouterProvider } from 'react-router'
import './index.css'
import PublicLayout from './layouts/PublicLayout'
import AppLayout from './layouts/AppLayout'
import Landing from './pages/public/Landing'
import NotFound from './pages/public/NotFound'
import Feed from './pages/app/Feed'

const router = createBrowserRouter([
  {
    element: <PublicLayout />,
    children: [
      { path: '/', element: <Landing /> },
      { path: '*', element: <NotFound /> },
    ],
  },
  {
    path: '/app',
    element: <AppLayout />,
    children: [
      { index: true, element: <Feed /> },
      { path: '*', element: <NotFound /> },
    ],
  },
])

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <RouterProvider router={router} />
  </StrictMode>,
)
