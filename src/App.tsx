import { Route, Routes } from "react-router-dom";
import { AuthProvider } from "./auth/AuthProvider.js";
import {
    CustomerAccountPage,
    CustomerAuthPage,
    CustomerBillPage,
    CustomerMenuPage,
    OrderTrackingPage,
} from "./pages/CustomerPages.js";
import { HomePage } from "./pages/HomePage.js";
import { LoginPage } from "./pages/LoginPage.js";
import { StaffBoardPage } from "./pages/StaffBoardPage.js";
import { ManagePage, PlatformPage } from "./pages/ManagePage.js";
import { PlaceholderPage } from "./pages/PlaceholderPage.js";

export default function App() {
    return (
        <AuthProvider>
            <Routes>
                <Route path="/" element={<HomePage />} />
                <Route path="/giris" element={<LoginPage />} />
                <Route path="/masa/:token" element={<CustomerMenuPage />} />
                <Route
                    path="/masa/:token/giris"
                    element={<CustomerAuthPage mode="login" />}
                />
                <Route
                    path="/masa/:token/kayit"
                    element={<CustomerAuthPage mode="register" />}
                />
                <Route
                    path="/masa/:token/adisyon"
                    element={<CustomerBillPage />}
                />
                <Route
                    path="/masa/:token/hesabim"
                    element={<CustomerAccountPage />}
                />
                <Route
                    path="/masa/:token/siparis/:orderNumber"
                    element={<OrderTrackingPage />}
                />
                <Route path="/garson/*" element={<StaffBoardPage />} />
                <Route
                    path="/mutfak/*"
                    element={<StaffBoardPage kitchenMode />}
                />
                <Route path="/yonetim/*" element={<ManagePage />} />
                <Route path="/platform/*" element={<PlatformPage />} />
                <Route
                    path="*"
                    element={<PlaceholderPage title="Sayfa bulunamadı" />}
                />
            </Routes>
        </AuthProvider>
    );
}
