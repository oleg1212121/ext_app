import NavBar from "../Components/NavBar.jsx";

export default function Main({children}) {
    return (
        <div className="h-screen flex flex-col">
            <div className="shrink-0">
                <NavBar/>
            </div>
            <main className="flex-1 min-h-0 flex flex-col overflow-hidden">
                {children}
            </main>
        </div>
    )
}
