import React from 'react';
import Main from '../Layouts/Main.jsx'
import { useI18n } from '../i18n'

const Dashboard = () => {
    const { t } = useI18n()

    return (
        <>
            <h1>{t('dashboard.dashboard')}</h1>
        </>
    )
}

Dashboard.layout = (page) => <Main children={page}/>
export default Dashboard;
