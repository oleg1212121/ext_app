import React from 'react';
import Main from '../Layouts/Main.jsx'

const Dashboard = () => {
    return (
        <>
            <h1>Dashboard</h1>
        </>
    )
}

Dashboard.layout = (page) => <Main children={page}/>
export default Dashboard;
